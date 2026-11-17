<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/config/database.php";

 $db = (new Database())->connect();

 $id   = (int) ($_GET['id'] ?? 0);
 $task = (int) ($_GET['task'] ?? 0);

if ($id > 0) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

header("Location: my_tasks.php?feedback=" . $task);
exit;