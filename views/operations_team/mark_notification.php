<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/config/database.php";

 $db = (new Database())->connect();
 $user = (int) $_SESSION['user_id'];

 $id = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user");
    $stmt->execute([':id' => $id, ':user' => $user]);
    header("Location: my_tasks.php");
    exit;
}

 $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :user AND is_read = 0");
 $stmt->execute([':user' => $user]);
header("Location: my_tasks.php");
exit;