<?php
require_once __DIR__ . "/../../../app/config/database.php";
$todayOmCount = 0;
try {
    $dbOmSidebar = (new Database())->connect();
    $stmtTodayOm = $dbOmSidebar->query("
        SELECT COUNT(*) 
        FROM tasks 
        WHERE (DATE(created_at) = CURDATE() OR DATE(task_created_at) = CURDATE())
    ");
    $todayOmCount = (int)$stmtTodayOm->fetchColumn();

    $stmtChatUnread = $dbOmSidebar->prepare("
        SELECT COUNT(*) FROM chat_messages 
        WHERE receiver_id = ? 
          AND is_read = 0 
          AND deleted_for_receiver = 0 
          AND is_deleted_for_everyone = 0
    ");
    $stmtChatUnread->execute([$_SESSION['user_id'] ?? 0]);
    $unreadChatCount = (int)$stmtChatUnread->fetchColumn();
} catch (Exception $e) {}
$unreadChatCount = $unreadChatCount ?? 0;
?>
<div class="w-64 min-h-screen bg-slate-900 text-white">

    <div class="p-6 border-b border-slate-700 text-center">
        <img src="../../assets/images/logo.png" class="w-36 mx-auto mb-3">
        <h2 class="text-xl font-bold tracking-wide">Digiink Task Flow</h2>
        <p class="text-base text-slate-400">Operations Manager</p>
    </div>

    <ul class="mt-6 space-y-2 text-base font-medium">

        <!-- DASHBOARD -->
        <li>
            <a href="dashboard.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">

                <span class="material-icons text-xl">dashboard</span>
                Dashboard
            </a>
        </li>

        <!-- TODAY'S TASKS -->
        <li>
            <a href="today_tasks.php"
               class="flex items-center justify-between px-5 py-3 rounded-lg hover:bg-slate-800 transition group">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-amber-400">calendar_today</span>
                    <span class="group-hover:text-amber-400 transition-colors">Today's Tasks</span>
                </div>
                <?php if ($todayOmCount > 0): ?>
                <span class="bg-amber-500 text-slate-900 text-[10px] font-bold px-2 py-0.5 rounded-full">
                    <?= $todayOmCount ?>
                </span>
                <?php endif; ?>
            </a>
        </li>

        <!-- INCOMING -->
        <li>
            <a href="incoming_tasks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">

                <span class="material-icons text-xl">assignment</span>
                Incoming Tasks
            </a>
        </li>

        <!-- ASSIGNED -->
        <li>
            <a href="assigned_tasks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">

                <span class="material-icons text-xl">engineering</span>
                Assigned to Team
            </a>
        </li>

        <!-- all tasks -->
        <li>
            <a href="all_tasks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">

                <span class="material-icons text-xl">inventory_2</span>
                All Task Inventory
            </a>
        </li>

        <li>
            <a href="additional_repository.php"
               class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-indigo-600 transition-all group border border-transparent hover:border-indigo-400">
                <span class="material-icons text-xl text-amber-400">folder_shared</span>
                <span class="font-bold">Extra Assets Repo</span>
            </a>
        </li>

        <!-- REWORK -->
        <li>
            <a href="rework_tasks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">

                <span class="material-icons text-xl">refresh</span>
                Rework Tasks
            </a>
        </li>

        <!-- COMPLETED -->
        <li>
            <a href="completed_tasks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">

                <span class="material-icons text-xl">task_alt</span>
                Completed Tasks
            </a>
        </li>

        <!-- FEEDBACKS -->
        <li>
            <a href="feedbacks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">feedback</span>
                View Feedbacks
            </a>
        </li>

        <!-- TEAM QUERIES -->
        <li>
            <a href="team_queries.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl text-amber-400">help_outline</span>
                Team Queries
            </a>
        </li>

        <!-- DIGICHAT TEAM MESSENGER -->
        <li>
            <a href="../chat/index.php"
               class="flex items-center justify-between px-5 py-3 rounded-lg bg-indigo-900/40 text-indigo-300 hover:bg-indigo-800/60 hover:text-white transition border border-indigo-500/20">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-indigo-400">forum</span>
                    <span class="font-bold">DigiChat</span>
                </div>
                <span class="digichat-unread-badge bg-rose-500 text-white font-extrabold text-[10px] px-1.5 py-0.5 rounded-full leading-none shadow-xs <?= $unreadChatCount > 0 ? '' : 'hidden' ?>">
                    <?= $unreadChatCount > 9 ? '9+' : $unreadChatCount ?>
                </span>
            </a>
        </li>

        <!-- NOTIFICATION HISTORY -->
        <li>
            <a href="notifications_history.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition text-slate-300 hover:text-white">
                <span class="material-icons text-xl text-amber-400">notifications_active</span>
                <span>Notifications Archive</span>
            </a>
        </li>


        <!--  QC SECTION START -->
        <li class="px-5 pt-4 text-xs uppercase tracking-wider text-slate-400">
            Quality Control
        </li>

        <!-- QC DASHBOARD -->
        <li>
            <a href="../qc/dashboard.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-indigo-700 transition">

                <span class="material-icons text-xl">fact_check</span>
                QC Dashboard
            </a>
        </li>

        <!-- QC REVIEW -->
        <li>
            <a href="../qc/pending_tasks.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-indigo-700 transition">

                <span class="material-icons text-xl">rule</span>
                QC Review Tasks
            </a>
        </li>

        <!-- QC SECTION END -->


        <!-- LOGOUT -->
        <li>
            <a href="../../logout.php"
               class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-red-600 transition">

                <span class="material-icons text-xl">logout</span>
                Logout
            </a>
        </li>

    </ul>

</div>
<?php @include_once __DIR__ . '/../../common/presence_tracker.php'; ?>