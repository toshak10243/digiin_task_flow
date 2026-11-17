<?php
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');

require_once "../../app/config/database.php";
require_once "../../app/services/GoogleDriveService.php";

$db = (new Database())->connect();

if (!isset($_GET['id'])) {
    header("Location: my_tasks.php?error=missing_id");
    exit;
}

$taskId = (int)$_GET['id'];
$userId = (int)$_SESSION['user_id'];

// 1. Verify Ownership & Stage (Can ONLY recall while in 'qc' review before approval)
$stmt = $db->prepare("
    SELECT id, task_key, team_file, drive_folder_id, drive_operations_folder_id, current_stage, status
    FROM tasks
    WHERE id = :id AND assigned_to = :uid
");
$stmt->execute([':id' => $taskId, ':uid' => $userId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header("Location: my_tasks.php?error=not_found");
    exit;
}

if ($task['current_stage'] !== 'qc') {
    // If already approved or completed, cannot recall
    header("Location: my_tasks.php?error=cannot_recall");
    exit;
}

// 2. Clean Up Local Staged Files
if (!empty($task['team_file'])) {
    $localZipPath = __DIR__ . '/../../' . $task['team_file'];
    if (file_exists($localZipPath)) {
        @unlink($localZipPath);
    }
}

$safeKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $task['task_key']);
$localTeamDir = __DIR__ . '/../../uploads/team_work/' . $safeKey;
if (is_dir($localTeamDir)) {
    $files = glob($localTeamDir . '/*');
    if ($files) {
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
    }
    @rmdir($localTeamDir);
}

// 3. Clean Up Google Drive Operations Files
try {
    $drive = new GoogleDriveService();
    if ($drive->isReady() && !empty($task['drive_folder_id'])) {
        $drive->clearOperationsFolder($task['drive_folder_id'], $task['drive_operations_folder_id'] ?? null);
    }
} catch (\Exception $eDrive) {
    error_log("Google Drive clear operations files failed: " . $eDrive->getMessage());
}

// 4. Reset Task Stage to 'operations_team' so expert can re-upload
$resetStmt = $db->prepare("
    UPDATE tasks SET
        current_stage         = 'operations_team',
        team_file             = NULL,
        team_notes            = NULL,
        drive_operations_link = NULL
    WHERE id = :id
");
$resetStmt->execute([':id' => $taskId]);

// 5. Remove any pending QC notification for this task
try {
    $db->prepare("DELETE FROM notifications WHERE task_id = :id AND type = 'task_completed'")
       ->execute([':id' => $taskId]);
} catch (\Exception $ignore) {}

header("Location: my_tasks.php?msg=recalled");
exit;
