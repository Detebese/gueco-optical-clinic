<?php
// Role check endpoint disabled to prevent unauthenticated user & role enumeration
define('BASE_URL', '');
require_once 'config/functions.php';

header('Content-Type: application/json');
echo json_encode(['role' => null]);
exit;
