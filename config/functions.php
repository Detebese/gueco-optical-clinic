<?php
// ============================================================
// HELPER FUNCTIONS
// Gueco Optical Clinic Management System
// ============================================================

require_once __DIR__ . '/db.php';

// --- Session & Auth ---

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function isPatientLoggedIn(): bool {
    startSession();
    return isset($_SESSION['patient_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $currentUser = null;
    if ($currentUser !== null) {
        return $currentUser;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, full_name, role, email, phone, status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            // Synchronize active session with current database data
            $_SESSION['user_name']  = $user['full_name'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            $currentUser = $user;
            return $currentUser;
        }
    } catch (Exception $e) {
        // Fallback to session data
    }
    $currentUser = [
        'id'        => $_SESSION['user_id'],
        'full_name' => $_SESSION['user_name'] ?? 'Admin',
        'role'      => $_SESSION['user_role'] ?? 'admin',
        'email'     => $_SESSION['user_email'] ?? '',
    ];
    return $currentUser;
}

function requireRole(string ...$roles): void {
    startSession();
    if (!isLoggedIn()) {
        header('Location: /gueco-optical/login.php');
        exit;
    }
    if (!in_array($_SESSION['user_role'], $roles)) {
        header('Location: /gueco-optical/unauthorized.php');
        exit;
    }
}

function requirePatientLogin(): void {
    startSession();
    if (!isPatientLoggedIn()) {
        header('Location: /gueco-optical/index.php');
        exit;
    }
}

function getDashboardUrl(string $role): string {
    return match($role) {
        'admin'     => '/gueco-optical/admin/dashboard.php',
        'doctor'    => '/gueco-optical/doctor/dashboard.php',
        'saleslady' => '/gueco-optical/saleslady/dashboard.php',
        default     => '/gueco-optical/login.php',
    };
}

function getRoleLabel(string $role): string {
    return match($role) {
        'admin'     => 'Administrator',
        'doctor'    => 'Optometrist',
        'saleslady' => 'Saleslady / Cashier',
        default     => ucfirst($role),
    };
}

// --- Security & Rate Limiting ---

function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    startSession();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        die('Invalid or expired security token (CSRF). Please refresh the page and try again.');
    }
}

function checkRateLimit(string $key, int $maxAttempts = 5, int $decaySeconds = 900): bool {
    startSession();
    $now = time();
    if (!isset($_SESSION['rate_limits'][$key])) {
        return true;
    }
    $entry = $_SESSION['rate_limits'][$key];
    if ($now > $entry['reset_at']) {
        unset($_SESSION['rate_limits'][$key]);
        return true;
    }
    return $entry['attempts'] < $maxAttempts;
}

function recordFailedAttempt(string $key, int $decaySeconds = 900): int {
    startSession();
    $now = time();
    if (!isset($_SESSION['rate_limits'][$key]) || $now > $_SESSION['rate_limits'][$key]['reset_at']) {
        $_SESSION['rate_limits'][$key] = [
            'attempts' => 1,
            'reset_at' => $now + $decaySeconds
        ];
    } else {
        $_SESSION['rate_limits'][$key]['attempts']++;
    }
    return $_SESSION['rate_limits'][$key]['attempts'];
}

function clearRateLimit(string $key): void {
    startSession();
    if (isset($_SESSION['rate_limits'][$key])) {
        unset($_SESSION['rate_limits'][$key]);
    }
}

function getRateLimitRemainingSeconds(string $key): int {
    startSession();
    if (!isset($_SESSION['rate_limits'][$key])) return 0;
    return max(0, $_SESSION['rate_limits'][$key]['reset_at'] - time());
}

// --- Database Helpers ---

function getSetting(string $key): ?string {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : null;
}

function logActivity(string $action, string $module = '', ?int $userId = null, string $userType = 'staff'): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $stmt = $db->prepare(
            "INSERT INTO activity_logs (user_id, user_type, action, module, ip_address) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$uid, $userType, $action, $module, $ip]);
    } catch (Exception $e) {
        // Silently fail — don't break app for logging errors
    }
}

function generateInvoiceNo(): string {
    $prefix = getSetting('invoice_prefix') ?? 'GO-';
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM sales");
    $row = $stmt->fetch();
    $num = str_pad(($row['cnt'] + 1), 6, '0', STR_PAD_LEFT);
    return $prefix . date('Ymd') . '-' . $num;
}

// --- Formatting ---

function formatCurrency(float $amount): string {
    return '₱' . number_format($amount, 2);
}

function formatDate(string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    return date('F d, Y', strtotime($date));
}

function formatDateTime(string $datetime): string {
    if (!$datetime) return '—';
    return date('M d, Y h:i A', strtotime($datetime));
}

function formatTime(string $time): string {
    if (!$time) return '—';
    return date('h:i A', strtotime($time));
}

function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    if ($diff->d === 0 && $diff->h === 0) return $diff->i . 'm ago';
    if ($diff->d === 0) return $diff->h . 'h ago';
    if ($diff->d < 7) return $diff->d . 'd ago';
    return formatDate($datetime);
}

// --- Badge Helpers ---

function statusBadge(string $status): string {
    $map = [
        'pending'   => ['warning', 'clock'],
        'confirmed' => ['info',    'check-circle'],
        'completed' => ['success', 'check-double'],
        'cancelled' => ['danger',  'times-circle'],
        'no_show'   => ['secondary','user-times'],
        'active'    => ['success', 'circle'],
        'inactive'  => ['danger',  'circle'],
        'stock_in'  => ['success', 'arrow-up'],
        'stock_out' => ['danger',  'arrow-down'],
        'adjustment'=> ['warning', 'edit'],
    ];
    $cfg = $map[$status] ?? ['secondary', 'question-circle'];
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class='badge bg-{$cfg[0]}'><i class='fas fa-{$cfg[1]} me-1'></i>{$label}</span>";
}

function roleBadge(string $role): string {
    $map = [
        'admin'     => ['primary',   'shield-alt', 'Administrator'],
        'doctor'    => ['info',      'user-md',    'Optometrist'],
        'saleslady' => ['secondary', 'user-tie',   'Saleslady'],
    ];
    $cfg = $map[$role] ?? ['secondary', 'user', ucfirst($role)];
    return "<span class='badge bg-{$cfg[0]}'><i class='fas fa-{$cfg[1]} me-1'></i>{$cfg[2]}</span>";
}

// --- Pagination ---

function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages = (int) ceil($total / $perPage);
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'pages'       => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $currentPage > 1,
        'has_next'    => $currentPage < $totalPages,
    ];
}

// --- Stats for Dashboards ---

function getDashboardStats(): array {
    $db = getDB();
    $today = date('Y-m-d');

    // Total patients
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM patients WHERE status = 'active'");
    $totalPatients = $stmt->fetch()['cnt'];

    // Today's appointments
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE appointment_date = ? AND status NOT IN ('cancelled','no_show')");
    $stmt->execute([$today]);
    $todayAppointments = $stmt->fetch()['cnt'];

    // Today's sales
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as total FROM sales WHERE DATE(created_at) = ? AND status = 'completed'");
    $stmt->execute([$today]);
    $todaySales = $stmt->fetch()['total'];

    // Low stock items
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM products WHERE stock_quantity <= low_stock_alert AND status = 'active'");
    $lowStock = $stmt->fetch()['cnt'];

    // Monthly sales
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as total FROM sales WHERE MONTH(created_at) = MONTH(?) AND YEAR(created_at) = YEAR(?) AND status = 'completed'");
    $stmt->execute([$today, $today]);
    $monthlySales = $stmt->fetch()['total'];

    // Pending appointments
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE appointment_date >= ? AND status = 'pending'");
    $stmt->execute([$today]);
    $pendingAppts = $stmt->fetch()['cnt'];

    return compact('totalPatients','todayAppointments','todaySales','lowStock','monthlySales','pendingAppts');
}

// --- Email ---

function sendEmailOTP(string $toEmail, string $otp): bool {
    // In a real app, you would configure SMTP here.
    // For now, if no SMTP is configured, we'll log it.
    
    // We will use PHPMailer for this.
    require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        // $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER; 
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Replace with real host
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your_email@gmail.com'; // Replace with real username
        $mail->Password   = 'your_app_password'; // Replace with real password
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('no-reply@guecooptical.com', 'Gueco Optical Clinic');
        $mail->addAddress($toEmail);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset OTP';
        $mail->Body    = "Your OTP for password reset is: <b>{$otp}</b>. This OTP is valid for 15 minutes.";
        $mail->AltBody = "Your OTP for password reset is: {$otp}. This OTP is valid for 15 minutes.";

        // $mail->send(); // Uncomment when real SMTP is ready
        
        // Simulate success for now by logging it (for local testing)
        error_log("OTP for $toEmail is $otp");
        
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
