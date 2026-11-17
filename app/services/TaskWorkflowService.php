<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationService.php';

class TaskWorkflowService
{
    private static bool $schemaChecked = false;

    /**
     * Ensure assigned_at column exists in tasks table
     */
    public static function ensureColumns(PDO $db): void
    {
        if (self::$schemaChecked) {
            return;
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM `tasks` LIKE 'assigned_at'");
            if (!$stmt->fetch()) {
                $db->exec("ALTER TABLE `tasks` ADD COLUMN `assigned_at` DATETIME NULL AFTER `assigned_to`");
            }
        } catch (Exception $e) {
            // Ignore error if column exists or fails gracefully
        }

        self::$schemaChecked = true;
    }

    /**
     * Auto-withdraw tasks that have been assigned for 30+ days without submission to QC or completion.
     * Can be invoked automatically on page load or by a scheduler.
     */
    public static function autoWithdrawStaleTasks(PDO $db, int $days = 30): array
    {
        self::ensureColumns($db);

        $withdrawn = [];

        try {
            // Find tasks assigned > 30 days ago and still pending in operations_team stage (not submitted to QC or completed)
            $query = "
                SELECT t.id, t.task_key, t.task_name, t.assigned_to, u.name AS expert_name, u.username AS expert_username
                FROM tasks t
                JOIN users u ON t.assigned_to = u.id
                WHERE t.assigned_to IS NOT NULL
                  AND t.current_stage = 'operations_team'
                  AND t.status IN ('assigned', 'in_progress', 'rework')
                  AND COALESCE(t.assigned_at, t.task_created_at, t.created_at) < DATE_SUB(NOW(), INTERVAL :days DAY)
            ";

            $stmt = $db->prepare($query);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            $staleTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($staleTasks)) {
                return [];
            }

            $updateStmt = $db->prepare("
                UPDATE tasks
                SET assigned_to   = NULL,
                    current_stage = 'operations_manager',
                    status        = 'new',
                    assigned_at   = NULL
                WHERE id = :id
            ");

            foreach ($staleTasks as $task) {
                $taskId     = (int)$task['id'];
                $taskKey    = $task['task_key'];
                $expertId   = (int)$task['assigned_to'];
                $expertName = $task['expert_name'] ?: ($task['expert_username'] ?: 'Expert');

                $updateStmt->execute([':id' => $taskId]);

                // Notification to the Operations Team member
                $titleExp = "⚠️ Auto-Withdrawn (30 Days Inactive): Task {$taskKey} unassigned & returned to Operations Manager.";
                NotificationService::send($expertId, $taskId, $titleExp, 'task_auto_withdrawn');

                // Notification to Operations Managers
                $titleOm = "⏰ Auto-Withdrawn (30 Days Inactive): Task {$taskKey} was automatically returned from {$expertName} (pending 30+ days without submission).";
                NotificationService::sendToRole(['operations_manager', 'admin'], $taskId, $titleOm, 'task_auto_withdrawn');

                $withdrawn[] = [
                    'id'          => $taskId,
                    'task_key'    => $taskKey,
                    'expert_id'   => $expertId,
                    'expert_name' => $expertName
                ];
            }
        } catch (Exception $e) {
            error_log("TaskWorkflowService::autoWithdrawStaleTasks error: " . $e->getMessage());
        }

        return $withdrawn;
    }

    /**
     * Operations Manager withdraws a task from an assigned Operations Team member
     */
    public static function withdrawByOperationsManager(PDO $db, int $taskId, int $omUserId): array
    {
        self::ensureColumns($db);

        $stmt = $db->prepare("
            SELECT t.id, t.task_key, t.task_name, t.assigned_to, t.current_stage, t.status,
                   u.name AS expert_name, u.username AS expert_username
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.id = :id
        ");
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return ['success' => false, 'error' => 'Task not found'];
        }

        if (empty($task['assigned_to'])) {
            return ['success' => false, 'error' => 'This task is not currently assigned to any team member'];
        }

        if ($task['status'] === 'completed' || $task['current_stage'] === 'completed') {
            return ['success' => false, 'error' => 'Cannot withdraw a completed task'];
        }

        $expertId   = (int)$task['assigned_to'];
        $expertName = $task['expert_name'] ?: ($task['expert_username'] ?: 'Team Member');
        $taskKey    = $task['task_key'];

        // Perform withdrawal: reset to operations_manager incoming pool
        $up = $db->prepare("
            UPDATE tasks
            SET assigned_to   = NULL,
                current_stage = 'operations_manager',
                status        = 'new',
                assigned_at   = NULL
            WHERE id = :id
        ");
        $up->execute([':id' => $taskId]);

        // Clean up temporary team uploads if any
        self::cleanupTeamWorkFiles($taskKey, $task['team_file'] ?? null);

        // Fetch OM Name
        $omNameStmt = $db->prepare("SELECT name FROM users WHERE id = :id");
        $omNameStmt->execute([':id' => $omUserId]);
        $omName = $omNameStmt->fetchColumn() ?: 'Operations Manager';

        // 1. Notify Operations Team member
        $titleExpert = "⚠️ Task Withdrawn: Task {$taskKey} has been withdrawn by {$omName}.";
        NotificationService::send($expertId, $taskId, $titleExpert, 'task_withdrawn');

        // 2. Notify Operations Manager
        $titleOm = "✅ Task Withdrawn: Task {$taskKey} successfully withdrawn from {$expertName}.";
        NotificationService::send($omUserId, $taskId, $titleOm, 'task_withdrawn');

        return [
            'success'     => true,
            'message'     => "Task {$taskKey} has been withdrawn from {$expertName} and returned to Incoming Tasks.",
            'task_key'    => $taskKey,
            'expert_name' => $expertName
        ];
    }

    /**
     * Operations Team member returns a task back to Operations Manager
     */
    public static function returnByOperationsTeam(PDO $db, int $taskId, int $teamUserId): array
    {
        self::ensureColumns($db);

        $stmt = $db->prepare("
            SELECT t.id, t.task_key, t.task_name, t.assigned_to, t.current_stage, t.status,
                   u.name AS expert_name, u.username AS expert_username
            FROM tasks t
            JOIN users u ON t.assigned_to = u.id
            WHERE t.id = :id AND t.assigned_to = :uid
        ");
        $stmt->execute([':id' => $taskId, ':uid' => $teamUserId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return ['success' => false, 'error' => 'Task not found or not assigned to you'];
        }

        if ($task['current_stage'] === 'qc' || $task['current_stage'] === 'completed' || $task['status'] === 'completed') {
            return ['success' => false, 'error' => 'Task has already been submitted or completed and cannot be returned'];
        }

        $expertName = $task['expert_name'] ?: ($task['expert_username'] ?: 'Operations Team Member');
        $taskKey    = $task['task_key'];

        // Perform return: reset to operations_manager incoming pool
        $up = $db->prepare("
            UPDATE tasks
            SET assigned_to   = NULL,
                current_stage = 'operations_manager',
                status        = 'new',
                assigned_at   = NULL
            WHERE id = :id
        ");
        $up->execute([':id' => $taskId]);

        // Clean up temporary team uploads if any
        self::cleanupTeamWorkFiles($taskKey, $task['team_file'] ?? null);

        // 1. Notify Operations Managers
        $titleOm = "🚨 Task Returned: {$expertName} has released and returned task {$taskKey} back to Operations Manager.";
        NotificationService::sendToRole(['operations_manager', 'admin'], $taskId, $titleOm, 'task_returned');

        // 2. Notify Operations Team member
        $titleExpert = "ℹ️ Task Returned: Task {$taskKey} was returned to Operations Manager.";
        NotificationService::send($teamUserId, $taskId, $titleExpert, 'task_returned');

        return [
            'success'  => true,
            'message'  => "Task {$taskKey} returned to Operations Manager successfully.",
            'task_key' => $taskKey
        ];
    }

    /**
     * Clean up local temp zip / working directory if any
     */
    private static function cleanupTeamWorkFiles(string $taskKey, ?string $teamFile): void
    {
        if (!empty($teamFile)) {
            $zipPath = __DIR__ . '/../../' . $teamFile;
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
        }

        $safeKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $taskKey);
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
    }
}

