<?php
require_once __DIR__ . '/../config/database.php';

class NotificationService
{
    private static ?PDO $db = null;

    private static function getDb(): PDO
    {
        if (self::$db === null) {
            self::$db = (new Database())->connect();
        }
        return self::$db;
    }

    /**
     * Send notification to a specific user
     */
    public static function send(int $userId, ?int $taskId, string $title, string $type = 'system'): bool
    {
        if ($userId <= 0 || empty(trim($title))) {
            return false;
        }

        try {
            $db = self::getDb();
            // Ensure type column can accept strings
            self::ensureSchema();

            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, task_id, title, type, is_read, created_at)
                VALUES (:uid, :tid, :title, :type, 0, NOW())
            ");
            $saved = $stmt->execute([
                ':uid'   => $userId,
                ':tid'   => $taskId ?: null,
                ':title' => $title,
                ':type'  => $type
            ]);

            // Real-time Web Push notification to user's laptop/browser
            if ($saved) {
                try {
                    require_once __DIR__ . '/FCMService.php';
                    FCMService::sendToUser($userId, $title, $title, $taskId);
                } catch (Exception $eFcm) {
                    error_log("FCM sendToUser error: " . $eFcm->getMessage());
                }
            }

            return $saved;
        } catch (Exception $e) {
            error_log("NotificationService::send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification to all users of specific roles (e.g. 'operations_manager', 'admin')
     */
    public static function sendToRole($roles, ?int $taskId, string $title, string $type = 'system'): int
    {
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        try {
            $db = self::getDb();
            $inClause = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $db->prepare("SELECT id FROM users WHERE role IN ($inClause) AND status = 'active'");
            $stmt->execute($roles);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $count = 0;
            foreach ($userIds as $uid) {
                if (self::send((int)$uid, $taskId, $title, $type)) {
                    $count++;
                }
            }
            return $count;
        } catch (Exception $e) {
            error_log("NotificationService::sendToRole error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 1. Task Created -> Notify Operations Managers & Admins
     */
    public static function onTaskCreated(int $taskId, string $taskKey, string $taskName, string $creatorUsername, bool $isUrgent = false): void
    {
        $prefix = $isUrgent ? "🚨 URGENT: " : "📋 ";
        $title = "{$prefix}New Task Created: {$taskKey} by {$creatorUsername}";
        $type = $isUrgent ? "urgent_task" : "task_created";
        self::sendToRole(['operations_manager', 'admin'], $taskId, $title, $type);
    }

    /**
     * 2. Task Assigned -> Notify Operations Team Member
     */
    public static function onTaskAssigned(int $taskId, string $taskKey, int $expertUserId, bool $isUrgent = false): void
    {
        $prefix = $isUrgent ? "🚨 Urgent: " : "📌 ";
        $title = "{$prefix}Task Assigned to you: {$taskKey}";
        $type = $isUrgent ? "urgent_task" : "task_assigned";
        self::send($expertUserId, $taskId, $title, $type);
    }

    /**
     * 3. Task Deployed to QC -> Notify QC Team & Operations Managers
     */
    public static function onTaskDeployedToQC(int $taskId, string $taskKey, string $expertUsername): void
    {
        $title = "🚀 Work Deployed: {$taskKey} submitted by {$expertUsername} for QC review";
        self::sendToRole(['qc', 'operations_manager'], $taskId, $title, 'work_deployed');
    }

    /**
     * 4. QC Approved & Completed -> Notify BDE (Task Creator) & Operations Manager
     */
    public static function onQCApproved(int $taskId, string $taskKey, int $bdeUserId): void
    {
        // Notify BDE (Task Creator)
        $titleBde = "✅ Task Completed: {$taskKey} is approved by QC! Please review client delivery.";
        self::send($bdeUserId, $taskId, $titleBde, 'task_completed');

        // Notify Operations Managers
        $titleOm = "✅ QC Approved: {$taskKey} marked completed";
        self::sendToRole(['operations_manager', 'admin'], $taskId, $titleOm, 'task_completed');
    }

    /**
     * 5. QC Rework Requested -> Notify Assigned Expert & Operations Manager
     */
    public static function onQCRework(int $taskId, string $taskKey, int $expertUserId, string $notes = ''): void
    {
        $titleExp = "🔄 Rework Required: {$taskKey}" . (!empty($notes) ? " - " . mb_substr($notes, 0, 50) : "");
        self::send($expertUserId, $taskId, $titleExp, 'task_rework');

        $titleOm = "🔄 Rework Flagged: {$taskKey} sent back for revision by QC";
        self::sendToRole(['operations_manager'], $taskId, $titleOm, 'task_rework');
    }

    /**
     * 6. Feedback Added -> Notify Operations Manager & Admin
     */
    public static function onFeedbackSubmitted(int $taskId, string $taskKey): void
    {
        $title = "💬 Feedback Received: New client feedback on {$taskKey}";
        self::sendToRole(['operations_manager', 'admin'], $taskId, $title, 'feedback');
    }

    /**
     * 7. Feedback Forwarded -> Notify Expert
     */
    public static function onFeedbackForwarded(int $taskId, string $taskKey, int $expertUserId): void
    {
        $title = "💬 Client Feedback Forwarded on task {$taskKey}";
        self::send($expertUserId, $taskId, $title, 'feedback');
    }

    /**
     * 8. Feedback Solved / Work Uploaded -> Notify Operations Manager & QC
     */
    public static function onFeedbackWorkSubmitted(int $taskId, string $taskKey, string $expertUsername): void
    {
        $title = "🚀 Feedback Solved: {$taskKey} updated work uploaded by {$expertUsername}";
        self::sendToRole(['operations_manager', 'qc'], $taskId, $title, 'work_deployed');
    }

    /**
     * 9. Additional Files / Instructions Uploaded -> Notify Operations Manager & Assigned Expert
     */
    public static function onAdditionalFilesUploaded(int $taskId, string $taskKey, ?int $assignedTo = null, bool $hasNotes = false): void
    {
        $titleOm = $hasNotes 
            ? "📌 Additional instructions & updates provided for {$taskKey}" 
            : "📁 Additional files uploaded for {$taskKey}";
        self::sendToRole(['operations_manager', 'admin'], $taskId, $titleOm, 'additional_files');

        // Also notify assigned operations team member directly if task is already in their court
        if ($assignedTo && $assignedTo > 0) {
            $expertTitle = $hasNotes 
                ? "⚠️ Important Client Update: Additional instructions provided for {$taskKey}" 
                : "📁 Additional materials uploaded by client for {$taskKey}";
            self::send($assignedTo, $taskId, $expertTitle, 'additional_files');
        }
    }

    /**
     * Get recent notifications with unread count for user
     */
    public static function getForUser(int $userId, int $limit = 10): array
    {
        try {
            $db = self::getDb();
            self::ensureSchema();

            $stmt = $db->prepare("
                SELECT n.*, t.task_key, t.task_name, t.status AS task_status
                FROM notifications n
                LEFT JOIN tasks t ON n.task_id = t.id
                WHERE n.user_id = :uid
                ORDER BY n.id DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
            $stmtCount->execute([':uid' => $userId]);
            $unread = (int)$stmtCount->fetchColumn();

            return [
                'notifications' => $items,
                'unread'        => $unread
            ];
        } catch (Exception $e) {
            error_log("NotificationService::getForUser error: " . $e->getMessage());
            return ['notifications' => [], 'unread' => 0];
        }
    }

    /**
     * Mark single notification read
     */
    public static function markRead(int $notifId, int $userId): bool
    {
        try {
            $db = self::getDb();
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid");
            return $stmt->execute([':id' => $notifId, ':uid' => $userId]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Mark all notifications read for user
     */
    public static function markAllRead(int $userId): bool
    {
        try {
            $db = self::getDb();
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
            return $stmt->execute([':uid' => $userId]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Ensure table schema has column type modified from restrictive ENUM to VARCHAR(50)
     */
    private static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        try {
            $db = self::getDb();
            $db->exec("ALTER TABLE notifications MODIFY COLUMN `type` VARCHAR(50) DEFAULT 'system'");
        } catch (Exception $e) {}
        $checked = true;
    }
}
