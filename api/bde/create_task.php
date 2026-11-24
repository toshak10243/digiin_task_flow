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

    // ─── 1. Check Task Key Duplicate (GET) ───────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'check_task_key') {
        $key = trim($_GET['task_key'] ?? '');
        if (empty($key)) {
            echo json_encode(['exists' => false]);
            exit;
        }

        $stmt = $db->prepare("SELECT id, task_name, created_at FROM tasks WHERE LOWER(TRIM(task_key)) = LOWER(TRIM(:k)) LIMIT 1");
        $stmt->execute([':k' => $key]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode([
                'exists'     => true,
                'task_name'  => $existing['task_name'],
                'created_at' => $existing['created_at'] ? date('d M Y', strtotime($existing['created_at'])) : null
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
        exit;
    }

    // ─── 2. Create Task (POST) ──────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? $_POST;

        $userId = (int)($body['user_id'] ?? $_GET['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? 0);
        if ($userId <= 0) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }
        if ($userId <= 0) {
            $bdeStmt = $db->query("SELECT id FROM users WHERE role = 'bde' AND status = 'active' ORDER BY id ASC LIMIT 1");
            $userId = (int)($bdeStmt->fetchColumn() ?: 0);
        }

        $taskKey     = trim($body['task_key'] ?? '');
        $taskName    = trim($body['task_name'] ?? '');
        $clientName  = trim($body['client_name'] ?? '');
        $wordCount   = (int)($body['word_count'] ?? 0);
        $deadline    = trim($body['deadline'] ?? '');
        $university  = trim($body['university'] ?? '');
        $country     = trim($body['country'] ?? '');
        $subject     = trim($body['subject'] ?? '');
        $instructions= trim($body['instructions'] ?? '');
        $urgent      = (strtolower($body['urgent'] ?? '') === 'yes' || ($body['urgent'] ?? false) === true) ? 'yes' : 'no';
        $driveLink   = trim($body['drive_link'] ?? '');

        if (empty($taskKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Task Key is required']);
            exit;
        }

        if (empty($taskName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Task Name / Project Title is required']);
            exit;
        }

        if (empty($deadline)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Deadline is required']);
            exit;
        }

        // Duplicate key check
        $stmtCheck = $db->prepare("SELECT id, task_name FROM tasks WHERE LOWER(TRIM(task_key)) = LOWER(TRIM(:k)) LIMIT 1");
        $stmtCheck->execute([':k' => $taskKey]);
        $dupTask = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($dupTask) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Task Key '$taskKey' already exists (Task: {$dupTask['task_name']}). Please use a unique Task Key."
            ]);
            exit;
        }

        // Insert new task
        $sql = "INSERT INTO tasks 
            (task_key, task_name, client_name, task_created_at, deadline, word_count, university, country, subject, instructions, urgent, created_by, drive_folder_link, status, current_stage) 
            VALUES (:task_key, :task_name, :client_name, NOW(), :deadline, :word_count, :university, :country, :subject, :instructions, :urgent, :created_by, :drive_link, 'new', 'operations')";
        
        $stmt = $db->prepare($sql);
        $created = $stmt->execute([
            ':task_key'    => $taskKey,
            ':task_name'   => $taskName,
            ':client_name' => $clientName,
            ':deadline'    => $deadline,
            ':word_count'  => $wordCount,
            ':university'  => $university,
            ':country'     => $country,
            ':subject'     => $subject,
            ':instructions'=> $instructions,
            ':urgent'      => $urgent,
            ':created_by'  => $userId,
            ':drive_link'  => !empty($driveLink) ? $driveLink : null
        ]);

        if (!$created) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create task record']);
            exit;
        }

        $taskId = (int)$db->lastInsertId();

        // Get BDE username for notification
        $uStmt = $db->prepare("SELECT username FROM users WHERE id = :u");
        $uStmt->execute([':u' => $userId]);
        $bdeUsername = $uStmt->fetchColumn() ?: 'BDE';

        // Notify OM & Admins
        try {
            NotificationService::onTaskCreated(
                $taskId,
                $taskKey,
                $taskName,
                $bdeUsername,
                ($urgent === 'yes')
            );
        } catch (\Exception $eNotif) {}

        echo json_encode([
            'success'  => true,
            'message'  => 'Task created successfully',
            'task_id'  => $taskId,
            'task_key' => $taskKey
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
