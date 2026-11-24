<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/NotificationService.php';
date_default_timezone_set('Asia/Kolkata');

try {
    $db = (new Database())->connect();

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? $_POST;

    $taskId = (int)($body['task_id'] ?? 0);
    $feedback = trim($body['feedback'] ?? '');
    $isRework = !empty($body['is_rework']);

    if ($taskId <= 0 || empty($feedback)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'task_id and feedback are required']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, task_key, status FROM tasks WHERE id = :id");
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }

    if ($isRework) {
        $upd = $db->prepare("UPDATE tasks SET feedback = :fb, feedback_read = 0, status = 'rework' WHERE id = :id");
    } else {
        $upd = $db->prepare("UPDATE tasks SET feedback = :fb, feedback_read = 0 WHERE id = :id");
    }
    $upd->execute([':fb' => $feedback, ':id' => $taskId]);

    // Send notifications
    try {
        NotificationService::onFeedbackSubmitted($taskId, $task['task_key']);
    } catch (\Exception $eNotif) {}

    echo json_encode([
        'success'   => true,
        'message'   => $isRework ? 'Rework request submitted' : 'Feedback submitted successfully',
        'task_id'   => $taskId,
        'is_rework' => $isRework
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
