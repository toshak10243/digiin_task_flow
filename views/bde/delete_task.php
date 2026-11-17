<?php
require_once "../../bootstrap/app.php";

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bde');

require_once "../../app/config/database.php";
require_once "../../app/services/GoogleDriveService.php";

$db = (new Database())->connect();

if (isset($_GET['id'])) {

    $taskId = $_GET['id'];
    $userId = $_SESSION['user_id'];

    // Check task ownership & stage: BDE can ONLY delete if OM has NOT assigned/delivered it yet!
    $stmt = $db->prepare("
        SELECT id, file_path, drive_folder_id, assigned_to, current_stage, status
        FROM tasks
        WHERE id = :id
        AND created_by = :uid
    ");

    $stmt->execute([
        ':id'  => $taskId,
        ':uid' => $userId
    ]);

    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    // If OM has assigned or stage moved, strictly forbid deletion
    if (!$task || !empty($task['assigned_to']) || $task['current_stage'] !== 'operations_manager' || $task['status'] !== 'new') {
        header("Location: my_tasks.php?msg=locked_cannot_delete");
        exit;
    }

        /* =============================
           Delete Google Drive Folder
        ============================== */

        if (!empty($task['drive_folder_id'])) {

            $drive = new GoogleDriveService();
            $drive->deleteFolder($task['drive_folder_id']);

        }

        /* =============================
           Delete Old Local File (if any)
        ============================== */

        if (!empty($task['file_path'])) {

            $filePath = "../../" . $task['file_path'];

            if (file_exists($filePath)) {
                unlink($filePath);
            }

        }

        /* =============================
           Delete Database Record
        ============================== */

        $del = $db->prepare("DELETE FROM tasks WHERE id = :id");

        if ($del->execute([':id' => $taskId])) {

            header("Location: my_tasks.php?msg=deleted");
            exit;

        }

    header("Location: my_tasks.php?msg=error");
    exit;
}