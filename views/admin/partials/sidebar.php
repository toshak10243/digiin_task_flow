<?php
require_once __DIR__ . "/../../../app/config/database.php";
$todayAdminCount = 0;
try {
    $dbAdminSidebar = (new Database())->connect();
    $stmtTodayAdmin = $dbAdminSidebar->query("
        SELECT COUNT(*) 
        FROM tasks 
        WHERE (DATE(created_at) = CURDATE() OR DATE(task_created_at) = CURDATE())
    ");
    $todayAdminCount = (int)$stmtTodayAdmin->fetchColumn();

    $stmtChatUnread = $dbAdminSidebar->prepare("
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
        <p class="text-base text-slate-400">Admin Control Panel</p>
    </div>

    <ul class="mt-6 space-y-2 text-base font-medium">

        <li>
            <a href="dashboard.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">dashboard</span> Dashboard
            </a>
        </li>

        <li>
            <a href="today_tasks.php" class="flex items-center justify-between px-5 py-3 rounded-lg hover:bg-slate-800 transition group">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-xl text-amber-400">calendar_today</span>
                    <span class="group-hover:text-amber-400 transition-colors">Today's Tasks</span>
                </div>
                <?php if ($todayAdminCount > 0): ?>
                <span class="bg-amber-500 text-slate-900 text-[10px] font-bold px-2 py-0.5 rounded-full">
                    <?= $todayAdminCount ?>
                </span>
                <?php endif; ?>
            </a>
        </li>

        <li>
            <a href="manage_bde.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">groups</span> BDE Users
            </a>
        </li>

        <li>
            <a href="manage_bdm.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">groups</span> BD Manager
            </a>
        </li>

        <li>
            <a href="manage_operations_manager.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">supervisor_account</span> Operations Manager
            </a>
        </li>

        <li>
            <a href="manage_operations_team.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">engineering</span> Operations Team
            </a>
        </li>

        <li>
            <a href="manage_qc.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">verified</span> QC Users
            </a>
        </li>

        <li>
            <a href="reports.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition">
                <span class="material-icons text-xl">bar_chart</span> Reports
            </a>
        </li>

        <li>
            <a href="../chat/index.php" class="flex items-center justify-between px-5 py-3 rounded-lg bg-indigo-900/40 text-indigo-300 hover:bg-indigo-800/60 hover:text-white transition border border-indigo-500/20">
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
            <a href="notifications_history.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-slate-800 transition text-slate-300 hover:text-white">
                <span class="material-icons text-xl text-amber-400">notifications_active</span> Notifications
            </a>
        </li>

        <li>
            <a href="../../logout.php" class="flex items-center gap-3 px-5 py-3 rounded-lg hover:bg-red-600 transition">
                <span class="material-icons text-xl">logout</span> Logout
            </a>
        </li>

    </ul>
</div>
<?php @include_once __DIR__ . '/../../common/presence_tracker.php'; ?>
