<?php
define('BASE_URL', '');
require_once 'config/functions.php';
startSession();

// Log the logout activity
if (isLoggedIn()) {
    logActivity('Logout', 'Auth', $_SESSION['user_id']);
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
