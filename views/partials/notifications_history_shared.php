<?php
/**
 * Shared Notification History Renderer
 * Expects $allowedRole to be defined or checks current session role.
 */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../../app/config/database.php';
$db = (new Database())->connect();
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';

// Filters
$filterType = $_GET['type'] ?? 'all';
$filterDate = $_GET['date'] ?? '';
$search = trim($_GET['search'] ?? '');

$whereSql = "WHERE n.user_id = :uid";
$params = [':uid' => $userId];

if ($filterType !== 'all' && !empty($filterType)) {
    $whereSql .= " AND n.type = :type";
    $params[':type'] = $filterType;
}

if (!empty($filterDate)) {
    $whereSql .= " AND DATE(n.created_at) = :dt";
    $params[':dt'] = $filterDate;
}

if (!empty($search)) {
    $whereSql .= " AND (n.title LIKE :s OR t.task_key LIKE :s OR t.task_name LIKE :s)";
    $params[':s'] = "%{$search}%";
}

$stmt = $db->prepare("
    SELECT n.*, t.task_key, t.task_name, t.status AS task_status
    FROM notifications n
    LEFT JOIN tasks t ON n.task_id = t.id
    {$whereSql}
    ORDER BY n.id DESC
    LIMIT 100
");
$stmt->execute($params);
$historyNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count stats
$stmtStats = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM notifications
    WHERE user_id = :uid
");
$stmtStats->execute([':uid' => $userId]);
$historyStats = $stmtStats->fetch(PDO::FETCH_ASSOC);

function getHistoryMeta($type) {
    return match($type) {
        'urgent_task'    => ['icon' => 'priority_high', 'color' => 'text-rose-600',    'bg' => 'bg-rose-50',    'badge' => 'Urgent Task'],
        'task_created'   => ['icon' => 'add_task',      'color' => 'text-blue-600',    'bg' => 'bg-blue-50',    'badge' => 'New Task'],
        'task_assigned'  => ['icon' => 'assignment_ind','color' => 'text-indigo-600',  'bg' => 'bg-indigo-50',  'badge' => 'Task Assigned'],
        'work_deployed'  => ['icon' => 'rocket_launch', 'color' => 'text-sky-600',     'bg' => 'bg-sky-50',     'badge' => 'Work Deployed'],
        'task_completed' => ['icon' => 'verified',      'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50', 'badge' => 'Completed'],
        'task_rework'    => ['icon' => 'sync_problem',  'color' => 'text-amber-600',   'bg' => 'bg-amber-50',   'badge' => 'Rework'],
        'feedback'       => ['icon' => 'rate_review',   'color' => 'text-purple-600',  'bg' => 'bg-purple-50',  'badge' => 'Feedback'],
        'additional_files'=>['icon' => 'folder_zip',    'color' => 'text-amber-600',   'bg' => 'bg-amber-50',   'badge' => 'Additional Files'],
        'expert_query'   => ['icon' => 'contact_support','color' => 'text-orange-600', 'bg' => 'bg-orange-50',  'badge' => 'Team Query'],
        'task_withdrawn' => ['icon' => 'person_remove', 'color' => 'text-rose-600',    'bg' => 'bg-rose-50',    'badge' => 'Withdrawn'],
        'task_returned'  => ['icon' => 'assignment_return','color' => 'text-amber-600','bg' => 'bg-amber-50',   'badge' => 'Returned'],
        'task_auto_withdrawn' => ['icon' => 'alarm_off','color' => 'text-rose-600',   'bg' => 'bg-rose-50',    'badge' => 'Auto-Withdrawn'],
        default          => ['icon' => 'notifications', 'color' => 'text-slate-600',   'bg' => 'bg-slate-50',   'badge' => 'Notification']
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification History | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .material-icons { font-family: 'Material Icons'; font-weight: normal; font-style: normal; font-size: 24px; line-height: 1; text-transform: none; letter-spacing: normal; word-wrap: normal; white-space: nowrap; direction: ltr; -webkit-font-smoothing: antialiased; }
        .material-icons-round { font-family: 'Material Icons Round'; font-weight: normal; font-style: normal; font-size: 24px; line-height: 1; text-transform: none; letter-spacing: normal; word-wrap: normal; white-space: nowrap; direction: ltr; -webkit-font-smoothing: antialiased; }
        .custom-card { transition: all 0.2s ease; }
        .custom-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -5px rgba(0,0,0,0.06); }
    </style>
</head>
<body class="text-slate-900">

<div class="flex h-screen overflow-hidden">
    <?php 
    // Include the role's native sidebar if available
    $sidebarPath = __DIR__ . "/../{$userRole}/partials/sidebar.php";
    if (file_exists($sidebarPath)) {
        include $sidebarPath;
    }
    ?>

    <main class="flex-1 overflow-y-auto p-6 lg:p-10 max-w-7xl mx-auto">
        <!-- Top Navigation / Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
            <div>
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="p-2 bg-white rounded-xl border border-slate-200 text-slate-600 hover:text-indigo-600 hover:border-indigo-200 shadow-sm transition-all">
                        <span class="material-icons-round text-lg">arrow_back</span>
                    </a>
                    <h1 class="text-2xl lg:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2.5">
                        <span class="p-2 bg-indigo-600 rounded-xl shadow-md shadow-indigo-100 text-white">
                            <span class="material-icons-round text-lg">history</span>
                        </span>
                        Notification History
                    </h1>
                </div>
                <p class="text-xs font-semibold text-slate-400 mt-1.5 ml-11">
                    Complete archive of task alerts, approvals, and workflow events.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <a href="../notifications_mark.php?all=1" 
                   class="px-4 py-2.5 bg-white border border-slate-200 hover:border-indigo-300 text-indigo-600 hover:bg-indigo-50 rounded-xl text-xs font-extrabold transition-all shadow-sm flex items-center gap-2">
                    <span class="material-icons-round text-base">done_all</span> Mark All Read
                </a>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Notifications</span>
                    <h3 class="text-2xl font-black text-slate-800 mt-1"><?= (int)$historyStats['total'] ?></h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
                    <span class="material-icons-round text-xl">notifications</span>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-indigo-500">Unread Alerts</span>
                    <h3 class="text-2xl font-black text-indigo-600 mt-1"><?= (int)$historyStats['unread'] ?></h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-icons-round text-xl">mark_email_unread</span>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-emerald-500">Today's Activity</span>
                    <h3 class="text-2xl font-black text-emerald-600 mt-1"><?= (int)$historyStats['today'] ?></h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-icons-round text-xl">today</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <form method="GET" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6 flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by task key or text..."
                       class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
            </div>

            <div>
                <select name="type" onchange="this.form.submit()"
                        class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <option value="task_created" <?= $filterType === 'task_created' ? 'selected' : '' ?>>New Tasks</option>
                    <option value="task_assigned" <?= $filterType === 'task_assigned' ? 'selected' : '' ?>>Assignments</option>
                    <option value="work_deployed" <?= $filterType === 'work_deployed' ? 'selected' : '' ?>>Work Deployed</option>
                    <option value="task_completed" <?= $filterType === 'task_completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="task_rework" <?= $filterType === 'task_rework' ? 'selected' : '' ?>>Rework</option>
                    <option value="feedback" <?= $filterType === 'feedback' ? 'selected' : '' ?>>Feedbacks</option>
                    <option value="additional_files" <?= $filterType === 'additional_files' ? 'selected' : '' ?>>Extra Files</option>
                    <option value="urgent_task" <?= $filterType === 'urgent_task' ? 'selected' : '' ?>>Urgent</option>
                </select>
            </div>

            <div>
                <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()"
                       class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer">
            </div>

            <?php if(!empty($search) || !empty($filterDate) || $filterType !== 'all'): ?>
            <a href="notifications_history.php" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition-all">
                Clear Filters
            </a>
            <?php endif; ?>
        </form>

        <!-- Notification Timeline / Cards -->
        <div class="space-y-3">
            <?php if(count($historyNotifications) > 0): ?>
                <?php 
                $prevDate = '';
                foreach($historyNotifications as $n):
                    $meta = getHistoryMeta($n['type'] ?? '');
                    $isUnread = empty($n['is_read']);
                    $currentDate = date("d M Y", strtotime($n['created_at']));
                    
                    if ($currentDate !== $prevDate):
                        $prevDate = $currentDate;
                ?>
                    <div class="pt-4 pb-1">
                        <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 bg-slate-200/60 px-3 py-1 rounded-full">
                            <?= $currentDate === date("d M Y") ? "Today • " : "" ?><?= $currentDate ?>
                        </span>
                    </div>
                <?php endif; ?>

                <a href="../notifications_mark.php?id=<?= (int)$n['id'] ?>"
                   class="custom-card bg-white p-4 rounded-2xl border <?= $isUnread ? 'border-indigo-200 bg-indigo-50/10' : 'border-slate-200/80' ?> shadow-sm flex items-start gap-4 block hover:border-indigo-300">
                    <div class="w-10 h-10 rounded-2xl <?= $meta['bg'] ?> flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-icons-round text-lg <?= $meta['color'] ?>"><?= $meta['icon'] ?></span>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-md <?= $meta['bg'] ?> <?= $meta['color'] ?>">
                                    <?= $meta['badge'] ?>
                                </span>
                                <?php if(!empty($n['task_key'])): ?>
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-600 bg-slate-100 px-2 py-0.5 rounded-md">
                                    <?= htmlspecialchars($n['task_key']) ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <span class="text-[11px] font-semibold text-slate-400 flex items-center gap-1">
                                <span class="material-icons-round text-xs">access_time</span>
                                <?= date("h:i A", strtotime($n['created_at'])) ?>
                            </span>
                        </div>

                        <p class="text-xs font-bold text-slate-800 leading-snug">
                            <?= htmlspecialchars($n['title']) ?>
                        </p>

                        <?php if(!empty($n['task_name'])): ?>
                        <p class="text-[11px] text-slate-400 font-medium mt-1">
                            Task: <?= htmlspecialchars($n['task_name']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="shrink-0 flex items-center self-center pl-2">
                        <?php if($isUnread): ?>
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500" title="Unread"></span>
                        <?php else: ?>
                        <span class="material-icons-round text-slate-300 text-lg">check_circle</span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="bg-white rounded-3xl p-16 text-center border border-slate-200 shadow-sm">
                    <div class="w-16 h-16 rounded-2xl bg-slate-50 text-slate-300 flex items-center justify-center mx-auto mb-3">
                        <span class="material-icons-round text-4xl">inbox</span>
                    </div>
                    <h3 class="text-base font-bold text-slate-700">No Notifications Found</h3>
                    <p class="text-xs text-slate-400 mt-1 font-medium">There are no notification logs matching your filter criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

</body>
</html>
