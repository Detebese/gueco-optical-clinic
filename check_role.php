<?php
// Role check AJAX endpoint for login page auto-detection
define('BASE_URL', '');
require_once 'config/functions.php';

header('Content-Type: application/json');

$email = trim($_GET['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['role' => null]);
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare("SELECT role FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode([
            'role'       => $user['role'],
            'role_label' => getRoleLabel($user['role']),
        ]);
    } else {
        echo json_encode(['role' => null]);
    }
} catch (Exception $e) {
    echo json_encode(['role' => null]);
}
