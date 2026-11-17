<?php
require_once "bootstrap/app.php";
require_once "app/core/Session.php";
require_once "app/controllers/AuthController.php";

// Auto-redirect if already logged in (or auto-logged in via 30-day remember token)
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $role = strtolower(str_replace(' ', '_', $_SESSION['role']));
    header("Location: views/{$role}/dashboard.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $auth = new AuthController();
    $error = $auth->login($_POST['username'], $_POST['password']);
}
?>

<?php include "views/auth/login.php"; ?>

