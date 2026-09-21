<?php
// ============================================================
// DATABASE CONFIGURATION
// Gueco Optical Clinic Management System
// ============================================================

// Detect environment (Localhost vs Live Hostinger Server)
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal  = in_array($httpHost, ['localhost', '127.0.0.1', '::1']) 
            || (function_exists('str_starts_with') ? str_starts_with($httpHost, 'localhost:') : (strpos($httpHost, 'localhost:') === 0));

if ($isLocal) {
    // --- Local Development (XAMPP) ---
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'gueco_optical');
} else {
    // --- Hostinger Live Production ---
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_USER', getenv('DB_USER') ?: 'u503998737_gueco_user');
    define('DB_PASS', getenv('DB_PASS') ?: 'Mikebryan.tumulak2003');
    define('DB_NAME', getenv('DB_NAME') ?: 'u503998737_gueco_db');
}

define('DB_CHARSET', 'utf8mb4');

// Ensure default timezone is always Asia/Manila (Philippine Standard Time)
date_default_timezone_set('Asia/Manila');

// PDO Connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            http_response_code(500);
            die('A database connection error occurred. Please contact the clinic administrator.');
        }
    }
    return $pdo;
}
