<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

    // Auto-migrate column
    try {
        $db->exec("ALTER TABLE tasks ADD COLUMN additional_instructions TEXT NULL AFTER additional_files");
    } catch (\Exception $e) {}

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? $_POST;

    $taskId = (int)($body['task_id'] ?? $_GET['task_id'] ?? 0);
    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'task_id is required']);
        exit;
    }

    // GET: Fetch current additional instructions & extras info
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT id, task_key, task_name, client_name, word_count, deadline, instructions, additional_files, additional_instructions, drive_folder_link, drive_additional_link FROM tasks WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit;
        }

        echo json_encode(['success' => true, 'task' => $task]);
        exit;
    }

    // POST: Save additional instructions / links
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $additionalInstructions = trim($body['additional_instructions'] ?? '');
        $additionalLink = trim($body['additional_link'] ?? '');

        // Fetch task details for notification
        $stmtTask = $db->prepare("SELECT id, task_key, assigned_to, additional_files, additional_instructions FROM tasks WHERE id = :id");
        $stmtTask->execute([':id' => $taskId]);
        $task = $stmtTask->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit;
        }

        $params = [
            ':ai' => $additionalInstructions !== '' ? $additionalInstructions : $task['additional_instructions'],
            ':id' => $taskId
        ];
        $updateSql = "UPDATE tasks SET additional_instructions = :ai";

        if (!empty($additionalLink)) {
            $updateSql .= ", additional_files = :al";
            $params[':al'] = $additionalLink;
        }
        $updateSql .= " WHERE id = :id";

        $db->prepare($updateSql)->execute($params);

        // Notify OM & Assigned Expert
        try {
            $assignedTo = (int)($task['assigned_to'] ?? 0);
            $hasInstructions = ($additionalInstructions !== '');
            NotificationService::onAdditionalFilesUploaded($taskId, $task['task_key'], $assignedTo, $hasInstructions);
        } catch (\Exception $eNotif) {}

        echo json_encode([
            'success'                 => true,
            'message'                 => 'Additional instructions updated successfully',
            'additional_instructions' => $params[':ai']
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
