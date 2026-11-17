
<?php
require_once "../../app/config/database.php";
$db = (new Database())->connect();

$todayExpertCount = 0;
if (!empty($_SESSION['user_id'])) {
    try {
        $stmtTodayExp = $db->prepare("
            SELECT COUNT(*) 
            FROM tasks 
            WHERE assigned_to = ? 
            AND ((DATE(created_at) = CURDATE() OR DATE(task_created_at) = CURDATE()) OR (status IN ('assigned', 'in_progress', 'rework') AND DATE(deadline) = CURDATE()))
        ");
        $stmtTodayExp->execute([$_SESSION['user_id']]);
        $todayExpertCount = (int)$stmtTodayExp->fetchColumn();

        $stmtChatUnread = $db->prepare("
            SELECT COUNT(*) FROM chat_messages 
            WHERE receiver_id = ? 
              AND is_read = 0 
              AND deleted_for_receiver = 0 
              AND is_deleted_for_everyone = 0
        ");
        $stmtChatUnread->execute([$_SESSION['user_id']]);
        $unreadChatCount = (int)$stmtChatUnread->fetchColumn();
    } catch (Exception $e) {}
}
$unreadChatCount = $unreadChatCount ?? 0;
?>

<div class="w-64 min-h-screen bg-[#0a1a2f] text-white shadow-xl flex flex-col">

    <div class="p-6 border-b border-gray-700 text-center">
        <img src="../../assets/images/logo.png" class="w-32 mx-auto mb-3">
        <h1 class="text-xl font-bold tracking-wide">Digiink Task Flow</h1>
        <p class="text-sm text-gray-400 font-bold uppercase tracking-tighter">Expert Panel</p>
    </div>

    <ul class="mt-6 space-y-2 text-base text-gray-300 font-medium px-3 flex-1">

        <li>
            <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">dashboard</span> Dashboard
            </a>
        </li>

        <li>
            <a href="today_tasks.php" class="flex items-center justify-between px-4 py-3 hover:bg-[#122842] rounded-lg transition group">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-amber-400">calendar_today</span>
                    <span class="group-hover:text-amber-400 transition-colors">Today's Tasks</span>
                </div>
                <?php if ($todayExpertCount > 0): ?>
                <span class="bg-amber-500 text-slate-900 text-[10px] font-bold px-2 py-0.5 rounded-full">
                    <?= $todayExpertCount ?>
                </span>
                <?php endif; ?>
            </a>
        </li>

        <li>
            <a href="my_tasks.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">assignment</span> My Tasks
            </a>
        </li>

        <li>
            <a href="extra_assets.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition group">
                <span class="material-icons text-xl text-amber-400">folder_zip</span> 
                <span class="group-hover:text-amber-400 transition-colors">Extra Assets</span>
            </a>
        </li>

        <li>
            <a href="work_history.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">history</span> Work History
            </a>
        </li>

        <li>
    <a href="feedbacks.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition group">
        <span class="material-icons text-xl text-sky-400">rate_review</span>
        <span class="group-hover:text-sky-400 transition-colors">Feedbacks</span>

        <?php
        $count = 0;
        try {
            $feedbackCount = $db->prepare("
                SELECT COUNT(*)
                FROM tasks
                WHERE (assigned_to = ? OR forwarded_feedback_to = ?)
                AND feedback IS NOT NULL
                AND feedback != ''
                AND COALESCE(feedback_read, 0) = 0
            ");
            $feedbackCount->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $count = (int)$feedbackCount->fetchColumn();
        } catch (Exception $e) {
            try {
                $feedbackCount = $db->prepare("
                    SELECT COUNT(*)
                    FROM tasks
                    WHERE assigned_to = ?
                    AND feedback IS NOT NULL
                    AND feedback != ''
                    AND COALESCE(feedback_read, 0) = 0
                ");
                $feedbackCount->execute([$_SESSION['user_id']]);
                $count = (int)$feedbackCount->fetchColumn();
            } catch (Exception $e2) {}
        }

        if($count > 0):
        ?>
            <span class="ml-auto bg-indigo-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">
                <?= $count ?>
            </span>
        <?php endif; ?>
    </a>
</li>

        <li>
            <a href="rework_tasks.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">refresh</span> Rework Tasks
            </a>
        </li>

        <li>
            <a href="../chat/index.php" class="flex items-center justify-between px-4 py-3 bg-indigo-900/40 text-indigo-300 hover:bg-indigo-800/60 hover:text-white rounded-lg transition border border-indigo-500/20">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-indigo-400">forum</span>
                    <span class="font-bold">DigiChat</span>
                </div>
                <span class="digichat-unread-badge bg-rose-500 text-white font-extrabold text-[10px] px-1.5 py-0.5 rounded-full leading-none shadow-xs <?= $unreadChatCount > 0 ? '' : 'hidden' ?>">
                    <?= $unreadChatCount > 9 ? '9+' : $unreadChatCount ?>
                </span>
            </a>
        </li>

        <li>
            <a href="notifications_history.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition text-slate-300 hover:text-white">
                <span class="material-icons text-xl text-amber-400">notifications_active</span> Notifications
            </a>
        </li>

        <li class="mt-auto pt-4 border-t border-gray-800">
            <a href="../../logout.php" class="flex items-center gap-3 px-4 py-3 hover:bg-red-600 rounded-lg transition">
                <span class="material-icons text-xl">logout</span> Logout
            </a>
        </li>

    </ul>
</div>
<?php @include_once __DIR__ . '/../../common/presence_tracker.php'; ?>