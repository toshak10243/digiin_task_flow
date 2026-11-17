<?php
ini_set('memory_limit', '256M');
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '60M');

require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/config/database.php";

$db = (new Database())->connect();

// Auto-migrate: ensure feedback_files column exists
try {
    $db->exec("ALTER TABLE `tasks` ADD COLUMN `feedback_files` TEXT NULL AFTER `feedback`");
} catch (Exception $e) {}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = (int)($_POST['task_id'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if ($task_id <= 0) {
        header("Location: my_tasks.php?error=invalid_task");
        exit;
    }

    // Fetch task details
    $stmtTask = $db->prepare("SELECT id, task_key, feedback_files FROM tasks WHERE id = :id");
    $stmtTask->execute([':id' => $task_id]);
    $task = $stmtTask->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        header("Location: my_tasks.php?error=task_not_found");
        exit;
    }

    $taskKey = $task['task_key'];
    $safeKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $taskKey);

    // Existing files (if any)
    $existingFiles = [];
    if (!empty($task['feedback_files'])) {
        $decoded = json_decode($task['feedback_files'], true);
        if (is_array($decoded)) {
            $existingFiles = $decoded;
        }
    }

    // Handle new uploaded files / screenshots
    $uploadDir = __DIR__ . "/../../uploads/feedback_files/" . $safeKey . "/";
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $blockedExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'htaccess', 'cgi', 'pl', 'exe', 'sh', 'bat'];

    if (isset($_FILES['feedback_files']) && !empty($_FILES['feedback_files']['name'][0])) {
        foreach ($_FILES['feedback_files']['name'] as $idx => $fileName) {
            if ($_FILES['feedback_files']['error'][$idx] !== UPLOAD_ERR_OK) {
                continue;
            }

            $originalName = basename($fileName);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (in_array($ext, $blockedExts)) {
                continue; // Skip dangerous files
            }

            $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $uploadDir . $uniqueName;

            if (move_uploaded_file($_FILES['feedback_files']['tmp_name'][$idx], $targetPath)) {
                $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg']);
                $relativePath = "uploads/feedback_files/" . $safeKey . "/" . $uniqueName;
                $fileSize = filesize($targetPath);

                $existingFiles[] = [
                    'name'        => $originalName,
                    'path'        => $relativePath,
                    'ext'         => $ext,
                    'is_image'    => $isImage,
                    'size'        => formatFileSize($fileSize),
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
            }
        }
    }

    $feedbackFilesJson = !empty($existingFiles) ? json_encode($existingFiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $stmt = $db->prepare("
        UPDATE tasks 
        SET feedback       = :feedback,
            feedback_files = :feedback_files,
            feedback_read  = 0
        WHERE id = :id
    ");

    $stmt->execute([
        ':feedback'       => $feedback,
        ':feedback_files' => $feedbackFilesJson,
        ':id'             => $task_id
    ]);

    // Notify Operations Manager and Admin
    require_once "../../app/services/NotificationService.php";
    NotificationService::onFeedbackSubmitted($task_id, $taskKey);

    header("Location: my_tasks.php?feedback=1");
    exit;
}
