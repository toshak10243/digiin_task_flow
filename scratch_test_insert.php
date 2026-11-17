<?php
session_start();
$_SESSION['user_id'] = 14;
$_SESSION['role'] = 'operations_manager';
$_SESSION['username'] = 'di.arushi2505';

$_GET['action'] = 'create_group';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['name'] = 'Test Operations Hub';
$_POST['description'] = 'Operations and BDE discussion';
$_POST['members'] = [3, 11]; // Isha and Ishan

ob_start();
require __DIR__ . '/views/chat/api.php';
$output = ob_get_clean();

echo "OUTPUT:\n" . $output . "\n";
