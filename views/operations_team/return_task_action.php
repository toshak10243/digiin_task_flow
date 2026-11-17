<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');

require_once "../../app/config/database.php";
require_once "../../app/services/TaskWorkflowService.php";

$db = (new Database())->connect();
$teamUserId = (int)($_SESSION['user_id'] ?? 0);
$taskId = (int)($_POST['task_id'] ?? ($_GET['id'] ?? 0));
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($taskId <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid task ID']);
        exit;
    }
    header("Location: my_tasks.php?error=" . urlencode("Invalid task ID"));
    exit;
}

$result = TaskWorkflowService::returnByOperationsTeam($db, $taskId, $teamUserId);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if (!empty($result['success'])) {
    header("Location: my_tasks.php?msg=returned&key=" . urlencode($result['task_key'] ?? ''));
} else {
    header("Location: my_tasks.php?error=" . urlencode($result['error'] ?? 'Return failed'));
}
exit;

