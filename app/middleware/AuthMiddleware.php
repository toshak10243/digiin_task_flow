<?php
require_once __DIR__ . '/../core/Session.php';

// Sirf login check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}
?>
