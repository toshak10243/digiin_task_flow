<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/config/database.php";

 $db = (new Database())->connect();

 $id = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    // Pehle notification fetch karo
    $stmtFetch = $db->prepare("SELECT type, task_id FROM notifications WHERE id = :id");
    $stmtFetch->execute([':id' => $id]);
    $notif = $stmtFetch->fetch(PDO::FETCH_ASSOC);

    // Mark read
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id");
    $stmt->execute([':id' => $id]);

    // Type-based redirect
    if ($notif) {
        if ($notif['type'] === 'expert_query') {
            header("Location: team_queries.php");
            exit;
        }
        if ($notif['type'] === 'feedback') {
            header("Location: feedbacks.php");
            exit;
        }
        if ($notif['type'] === 'additional_files') {
            header("Location: additional_repository.php?task_id=" . (int)$notif['task_id']);
            exit;
        }
    }
}

header("Location: dashboard.php");
exit;