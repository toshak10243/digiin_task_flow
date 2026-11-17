<?php
// Highlights the current page in the nav — purely cosmetic, no route/logic changes.
$currentPage = basename($_SERVER['PHP_SELF']);

$todayBdeCount = 0;
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . "/../../../app/config/database.php";
    try {
        $dbSidebar = (new Database())->connect();
        $stmtTodayBde = $dbSidebar->prepare("
            SELECT COUNT(*) 
            FROM tasks 
            WHERE created_by = ? 
            AND (DATE(created_at) = CURDATE() OR DATE(task_created_at) = CURDATE())
        ");
        $stmtTodayBde->execute([$_SESSION['user_id']]);
        $todayBdeCount = (int)$stmtTodayBde->fetchColumn();

        $stmtChatUnread = $dbSidebar->prepare("
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

$navItems = [
    ['href' => 'dashboard.php',              'icon' => 'dashboard',             'label' => 'Dashboard'],
    ['href' => 'today_tasks.php',            'icon' => 'calendar_today',        'label' => "Today's Tasks", 'badge' => $todayBdeCount],
    ['href' => 'create_task.php',            'icon' => 'add_circle',            'label' => 'Create Task'],
    ['href' => 'my_tasks.php',               'icon' => 'assignment',            'label' => 'My Tasks'],
    ['href' => 'notifications_history.php',  'icon' => 'notifications_active',  'label' => 'Notifications'],
    ['href' => '../chat/index.php',          'icon' => 'forum',                 'label' => 'DigiChat'],
];
?>
<div class="w-56 h-screen flex flex-col justify-between text-white flex-shrink-0 select-none sticky top-0"
     style="background: linear-gradient(180deg, #0b1420 0%, #101d29 100%);">

    <div>
        <!-- Brand -->
        <div class="p-4 border-b border-white/10 text-center">
            <img src="../../assets/images/logo.png" class="w-20 mx-auto mb-2" alt="Digiink logo">
            <h2 class="font-display text-base font-bold tracking-wide text-white">Digiink Task Flow</h2>
            <p class="text-[9px] text-slate-400 uppercase tracking-widest mt-1">BDE Dashboard</p>
        </div>

        <!-- Nav -->
        <nav class="mt-4 px-2.5 space-y-1">
            <?php foreach ($navItems as $item):
                $isActive = ($currentPage === $item['href']);
            ?>
            <a href="<?= $item['href'] ?>"
               class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium border-l-[3px] transition-all
                      <?= $isActive
                          ? 'bg-white/10 border-[#eba940] text-white'
                          : 'border-transparent text-slate-300 hover:bg-white/5 hover:text-white' ?>">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="material-icons-round text-[19px] <?= $isActive ? 'text-[#eba940]' : 'text-slate-400' ?>">
                        <?= $item['icon'] ?>
                    </span>
                    <span class="truncate"><?= $item['label'] ?></span>
                </div>
                <?php if ($item['label'] === 'DigiChat'): ?>
                <span class="digichat-unread-badge ml-auto bg-rose-500 text-white font-extrabold text-[10px] px-1.5 py-0.5 rounded-full leading-none shadow-xs <?= $unreadChatCount > 0 ? '' : 'hidden' ?>">
                    <?= $unreadChatCount > 9 ? '9+' : $unreadChatCount ?>
                </span>
                <?php elseif (!empty($item['badge'])): ?>
                <span class="ml-2 bg-[#eba940] text-slate-900 font-bold text-[10px] px-1.5 py-0.5 rounded-full leading-none">
                    <?= $item['badge'] ?>
                </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- Footer: role + logout -->
    <div class="p-3 border-t border-white/10">
        <?php if (!empty($_SESSION['role'])): ?>
        <div class="px-4 py-2 mb-1 flex items-center gap-2">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
            <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest">
                <?= htmlspecialchars(str_replace('_', ' ', $_SESSION['role'])) ?>
            </span>
        </div>
        <?php endif; ?>
        <a href="../../logout.php"
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium text-slate-300 hover:bg-red-600/90 hover:text-white transition-all">
            <span class="material-icons-round text-[19px]">logout</span> Logout
        </a>
    </div>
</div>
<?php @include_once __DIR__ . '/../../common/presence_tracker.php'; ?>