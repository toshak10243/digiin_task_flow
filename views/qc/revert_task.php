<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('qc');

require_once "../../app/config/database.php";

$db = (new Database())->connect();

if (!isset($_GET['id'])) {
    header("Location: history.php?error=missing_id");
    exit;
}

$taskId = (int)$_GET['id'];

// 1. Verify Task is currently completed
$stmt = $db->prepare("SELECT id, task_key, current_stage, status FROM tasks WHERE id = :id");
$stmt->execute([':id' => $taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header("Location: history.php?error=not_found");
    exit;
}

if ($task['status'] !== 'completed' && $task['current_stage'] !== 'completed') {
    header("Location: history.php?error=invalid_status");
    exit;
}

// 2. Revert back to QC queue
$db->prepare("
    UPDATE tasks SET
        current_stage = 'qc',
        status        = 'assigned'
    WHERE id = :id
")->execute([':id' => $taskId]);

// 3. Delete the completed notification sent to BDE
try {
    $db->prepare("DELETE FROM notifications WHERE task_id = :id AND title LIKE 'Task % has been completed%'")
       ->execute([':id' => $taskId]);
} catch (\Exception $ignore) {}

header("Location: history.php?msg=reverted");
exit;
