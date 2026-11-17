<?php
require_once __DIR__ . "/../../../app/config/database.php";
$todayBdmCount = 0;
try {
    $dbBdmSidebar = (new Database())->connect();
    $stmtTodayBdm = $dbBdmSidebar->query("
        SELECT COUNT(*) 
        FROM tasks 
        WHERE (DATE(created_at) = CURDATE() OR DATE(task_created_at) = CURDATE())
    ");
    $todayBdmCount = (int)$stmtTodayBdm->fetchColumn();

    $stmtChatUnread = $dbBdmSidebar->prepare("
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
<aside class="w-64 min-h-screen bg-[#0a1a2f] text-white shadow-xl">

    <!-- LOGO AREA -->
<div class="p-6 border-b border-slate-700 text-center">
        <img src="../../assets/images/logo.png" class="w-36 mx-auto mb-2">
        <h2 class="text-lg font-bold">Digiink Task Flow</h2>
        <p class="text-sm text-slate-400">BDM Dashboard</p>
    </div>

    <!-- MENU -->
    <ul class="mt-4 space-y-2 text-gray-300 font-medium">

        <li>
            <a href="../bdmanager/dashboard.php" class="block p-3 hover:bg-[#122842] rounded-lg">
                <span class="material-icons align-middle mr-2 text-blue-400">dashboard</span>
                Dashboard
            </a>
        </li>

        <li>
            <a href="../bdmanager/today_tasks.php" class="flex items-center justify-between p-3 hover:bg-[#122842] rounded-lg group">
                <div>
                    <span class="material-icons align-middle mr-2 text-amber-400">calendar_today</span>
                    <span class="group-hover:text-amber-400 transition-colors">Today's Tasks</span>
                </div>
                <?php if ($todayBdmCount > 0): ?>
                <span class="bg-amber-500 text-slate-900 text-[10px] font-bold px-2 py-0.5 rounded-full">
                    <?= $todayBdmCount ?>
                </span>
                <?php endif; ?>
            </a>
        </li>

        <li>
            <a href="../bdmanager/all_tasks.php" class="block p-3 hover:bg-[#122842] rounded-lg">
                <span class="material-icons align-middle mr-2 text-green-400">assignment</span>
                All Tasks
            </a>
        </li>

        <li>
            <a href="../bdmanager/completed_tasks.php" class="block p-3 hover:bg-[#122842] rounded-lg">
                <span class="material-icons align-middle mr-2 text-green-500">task_alt</span>
                Completed Tasks
            </a>
        </li>

        <li>
            <a href="../bdmanager/rework_tasks.php" class="block p-3 hover:bg-[#122842] rounded-lg">
                <span class="material-icons align-middle mr-2 text-red-400">assignment_late</span>
                Rework Monitoring
            </a>
        </li>

        <li>
            <a href="../bdmanager/urgent_tasks.php" class="block p-3 hover:bg-[#122842] rounded-lg">
                <span class="material-icons align-middle mr-2 text-red-500">priority_high</span>
                Urgent Tasks
            </a>
        </li>

        <li>
            <a href="../bdmanager/daily_report.php" class="block p-3 hover:bg-[#122842] rounded-lg">
                <span class="material-icons align-middle mr-2 text-yellow-400">download</span>
                Daily Excel Report
            </a>
        </li>

        <li>
            <a href="../chat/index.php" class="flex items-center justify-between p-3 bg-indigo-900/40 text-indigo-300 hover:bg-indigo-800/60 hover:text-white rounded-lg border border-indigo-500/20">
                <div>
                    <span class="material-icons align-middle mr-2 text-indigo-400">forum</span>
                    <strong>DigiChat</strong>
                </div>
                <span class="digichat-unread-badge bg-rose-500 text-white font-extrabold text-[10px] px-1.5 py-0.5 rounded-full leading-none shadow-xs <?= $unreadChatCount > 0 ? '' : 'hidden' ?>">
                    <?= $unreadChatCount > 9 ? '9+' : $unreadChatCount ?>
                </span>
            </a>
        </li>

        <li>
            <a href="notifications_history.php" class="block p-3 hover:bg-[#122842] rounded-lg text-slate-300 hover:text-white">
                <span class="material-icons align-middle mr-2 text-amber-400">notifications_active</span>
                Notifications
            </a>
        </li>

        <li>
            <a href="../../logout.php" class="block p-3 hover:bg-[#dc3545] rounded-lg">
                <span class="material-icons align-middle mr-2">logout</span>
                Logout
            </a>
        </li>

    </ul>
</aside>
<?php @include_once __DIR__ . '/../../common/presence_tracker.php'; ?>
