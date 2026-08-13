<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
startSession();

if (isPatientLoggedIn()) {
    logActivity('Patient Logout', 'Auth', $_SESSION['patient_id'], 'patient');
}

session_unset();
session_destroy();
header('Location: ../index.php');
exit;
