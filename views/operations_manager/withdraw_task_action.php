<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../app/services/TaskWorkflowService.php";

$db = (new Database())->connect();
$omUserId = (int)($_SESSION['user_id'] ?? 0);
$taskId = (int)($_POST['task_id'] ?? ($_GET['id'] ?? 0));
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($taskId <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid task ID']);
        exit;
    }
    header("Location: all_tasks.php?error=" . urlencode("Invalid task ID"));
    exit;
}

$result = TaskWorkflowService::withdrawByOperationsManager($db, $taskId, $omUserId);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

$ref = $_SERVER['HTTP_REFERER'] ?? 'all_tasks.php';
$separator = (strpos($ref, '?') !== false) ? '&' : '?';

if (!empty($result['success'])) {
    header("Location: " . $ref . $separator . "msg=withdrawn&key=" . urlencode($result['task_key'] ?? ''));
} else {
    header("Location: " . $ref . $separator . "error=" . urlencode($result['error'] ?? 'Withdrawal failed'));
}
exit;

