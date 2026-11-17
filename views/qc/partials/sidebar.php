<?php
require_once __DIR__ . "/../../../app/config/database.php";
$todayQcCount = 0;
try {
    $dbQcSidebar = (new Database())->connect();
    $stmtTodayQc = $dbQcSidebar->query("
        SELECT COUNT(*) 
        FROM tasks 
        WHERE current_stage = 'qc' 
        OR (status = 'completed' AND (DATE(created_at) = CURDATE() OR DATE(task_created_at) = CURDATE()))
    ");
    $todayQcCount = (int)$stmtTodayQc->fetchColumn();

    $stmtChatUnread = $dbQcSidebar->prepare("
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
<div class="w-64 min-h-screen bg-[#0a1a2f] text-white shadow-xl flex flex-col">

    <div class="p-6 border-b border-gray-700 text-center">
        <img src="../../assets/images/logo.png" class="w-32 mx-auto mb-3">
        <h1 class="text-xl font-bold tracking-wide">Digiink Task Flow</h1>
        <p class="text-sm text-gray-400">QC Panel</p>
    </div>

    <ul class="mt-6 space-y-2 text-base text-gray-300 font-medium px-3 flex-1">

        <!-- Dashboard -->
        <li>
            <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">dashboard</span>
                Dashboard
            </a>
        </li>

        <!-- Today's Tasks -->
        <li>
            <a href="today_tasks.php" class="flex items-center justify-between px-4 py-3 hover:bg-[#122842] rounded-lg transition group">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-amber-400">calendar_today</span>
                    <span class="group-hover:text-amber-400 transition-colors">Today's Tasks</span>
                </div>
                <?php if ($todayQcCount > 0): ?>
                <span class="bg-amber-500 text-slate-900 text-[10px] font-bold px-2 py-0.5 rounded-full">
                    <?= $todayQcCount ?>
                </span>
                <?php endif; ?>
            </a>
        </li>

        <!-- Pending Tasks -->
        <li>
            <a href="pending_tasks.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">assignment</span>
                Pending Tasks
            </a>
        </li>

        <!-- Review History -->
        <li>
            <a href="history.php" class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition">
                <span class="material-icons text-xl">history</span>
                Review History
            </a>
        </li>

        <!-- QUICK ACCESS SECTION -->
        <li class="pt-6 px-2">
            <div class="text-xs uppercase tracking-wider text-gray-500 mb-3">
                Quick Access
            </div>

            <a href="../operations_manager/dashboard.php"
               class="flex items-center justify-between px-4 py-3 bg-indigo-600 hover:bg-indigo-700 rounded-lg transition shadow-md">

                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl">manage_accounts</span>
                    <span>Operations Manager</span>
                </div>

                <span class="material-icons text-lg">
                    arrow_forward
                </span>
            </a>
        </li>

        <!-- DigiChat -->
        <li class="pt-2">
            <a href="../chat/index.php"
               class="flex items-center justify-between px-4 py-3 bg-indigo-900/40 text-indigo-300 hover:bg-indigo-800/60 hover:text-white rounded-lg transition border border-indigo-500/20">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-indigo-400">forum</span>
                    <span class="font-bold">DigiChat</span>
                </div>
                <span class="digichat-unread-badge bg-rose-500 text-white font-extrabold text-[10px] px-1.5 py-0.5 rounded-full leading-none shadow-xs <?= $unreadChatCount > 0 ? '' : 'hidden' ?>">
                    <?= $unreadChatCount > 9 ? '9+' : $unreadChatCount ?>
                </span>
            </a>
        </li>

        <!-- Notifications -->
        <li>
            <a href="notifications_history.php"
               class="flex items-center gap-3 px-4 py-3 hover:bg-[#122842] rounded-lg transition text-slate-300 hover:text-white">
                <span class="material-icons text-xl text-amber-400">notifications_active</span>
                <span>Notifications</span>
            </a>
        </li>

        <!-- Logout -->
        <li class="mt-auto pt-4">
            <a href="../../logout.php"
               class="flex items-center gap-3 px-4 py-3 hover:bg-red-600 rounded-lg transition">

                <span class="material-icons text-xl">logout</span>
                Logout
            </a>
        </li>

    </ul>

</div>
<?php @include_once __DIR__ . '/../../common/presence_tracker.php'; ?>