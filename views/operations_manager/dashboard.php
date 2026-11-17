<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
require_once "../../app/services/TaskWorkflowService.php";

 $db = (new Database())->connect();

// Auto-withdraw any tasks assigned for 30+ days without submission
TaskWorkflowService::autoWithdrawStaleTasks($db);

/* 📅 Daily Task Section (Date-Wise) */
$dashDate = $_GET['dash_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
    $dashDate = date('Y-m-d');
}

$stmtDaily = $db->prepare("
    SELECT t.*, u.name AS expert_name, bde.name AS bde_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN users bde ON t.created_by = bde.id
    WHERE (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)
    ORDER BY t.id DESC
");
$stmtDaily->execute([':dt' => $dashDate]);
$dailyTasks = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

$dailyStats = [
    'total'     => count($dailyTasks),
    'completed' => 0,
    'assigned'  => 0,
    'in_work'   => 0,
    'words'     => 0,
    'urgent'    => 0
];
foreach ($dailyTasks as $dt) {
    $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
    if (($dt['urgent'] ?? '') === 'yes') $dailyStats['urgent']++;
    if ($dt['status'] === 'completed') $dailyStats['completed']++;
    elseif ($dt['status'] === 'assigned') $dailyStats['assigned']++;
    elseif ($dt['status'] === 'in_progress') $dailyStats['in_work']++;
}

/* ===================== STATS ===================== */
 $incoming  = $db->query("SELECT COUNT(*) FROM tasks WHERE current_stage='operations_manager'")->fetchColumn();
 $assigned  = $db->query("SELECT COUNT(*) FROM tasks WHERE status='assigned'")->fetchColumn();
 $rework    = $db->query("SELECT COUNT(*) FROM tasks WHERE status='rework'")->fetchColumn();
 $urgent    = $db->query("SELECT COUNT(*) FROM tasks WHERE urgent='yes' AND current_stage='operations_manager'")->fetchColumn();
 $completed = $db->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn();

/* 🔴 Urgent Badge Count */
 $showCount = $urgent > 9 ? "9+" : $urgent;

/* 🔔 Notifications */
 $stmtNotify = $db->prepare("
    SELECT *
    FROM notifications
    WHERE user_id = :user
    ORDER BY created_at DESC
    LIMIT 10
");
 $stmtNotify->execute([
    ':user' => $_SESSION['user_id']
]);
 $notifications = $stmtNotify->fetchAll(PDO::FETCH_ASSOC);

 $stmtUnread = $db->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id = :user
    AND is_read = 0
");
 $stmtUnread->execute([
    ':user' => $_SESSION['user_id']
]);
 $unread = $stmtUnread->fetchColumn();

/* 📊 Status Chart */
 $statusData = $db->query("SELECT status, COUNT(*) as total FROM tasks GROUP BY status")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);

/* 👨‍💻 Team Performance */
 $teamPerf = $db->query("
    SELECT u.name, COUNT(t.id) as done
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    WHERE t.status='completed'
    GROUP BY t.assigned_to
    ORDER BY done DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* 🏆 Top Performer */
 $topUser = $teamPerf[0] ?? null;

/* 🕒 Recent Activity */
 $recent = $db->query("
    SELECT t.task_key, t.task_name, t.status, u.name AS user_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to=u.id
    ORDER BY t.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* 📝 Word Counts (Lifetime & Current Month Auto-Reset) */
$currMonth = date('Y-m');
$totalWords = (int) $db->query("SELECT COALESCE(SUM(word_count), 0) FROM tasks")->fetchColumn();
$stmtMonthly = $db->prepare("SELECT COALESCE(SUM(word_count), 0) FROM tasks WHERE DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = :currMonth");
$stmtMonthly->execute([':currMonth' => $currMonth]);
$monthlyWords = (int) $stmtMonthly->fetchColumn();

/* ===================== HELPERS ===================== */
 $hasStatusData = !empty($statusData) && array_sum($statusData) > 0;
 $hasTeamData   = count($teamPerf) > 0;

 $cards = [
    ['label' => 'Incoming',    'val' => $incoming,  'icon' => 'inbox',           'color' => 'indigo', 'sub' => 'Awaiting assignment'],
    ['label' => 'Assigned',    'val' => $assigned,  'icon' => 'assignment_ind',  'color' => 'amber',  'sub' => 'In active queue'],
    ['label' => 'Rework',      'val' => $rework,    'icon' => 'sync_problem',    'color' => 'rose',   'sub' => 'Needs revision'],
    ['label' => 'Urgent',      'val' => $urgent,    'icon' => 'bolt',            'color' => 'orange', 'sub' => 'Time-critical'],
    ['label' => 'Completed',   'val' => $completed, 'icon' => 'verified',        'color' => 'emerald','sub' => 'Delivered'],
    ['label' => 'Total Words', 'val' => number_format($totalWords), 'icon' => 'description', 'color' => 'purple', 'sub' => date('M') . ': ' . number_format($monthlyWords) . ' words', 'badge' => date('M') . ': ' . number_format($monthlyWords)],
];

function opsStatusBadgeClass($status) {
    return match($status) {
        'completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-100',
        'in_progress' => 'bg-indigo-50 text-indigo-700 border-indigo-100',
        'assigned'    => 'bg-amber-50 text-amber-700 border-amber-100',
        'rework'      => 'bg-rose-50 text-rose-700 border-rose-100',
        default       => 'bg-slate-100 text-slate-600 border-slate-200'
    };
}

function timeAgo($datetime) {
    if (empty($datetime)) return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return "just now";
    $mins = floor($diff / 60);
    if ($mins < 60) return $mins . " min ago";
    $hours = floor($mins / 60);
    if ($hours < 24) return $hours . "h ago";
    $days = floor($hours / 24);
    if ($days < 30) return $days . "d ago";
    $months = floor($days / 30);
    return $months . "mo ago";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ops Manager | Elite Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: #f8fafc; }

        .surface {
            background: #fff;
            border: 1px solid #eef0f3;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .kpi-card {
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.10);
            border-color: #e2e8f0;
        }
        .icon-chip {
            width: 2.25rem; height: 2.25rem;
            border-radius: 0.75rem;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .activity-row {
            border-radius: 12px;
            transition: background-color 0.15s ease, border-color 0.15s ease;
            border: 1px solid transparent;
        }
        .activity-row:hover {
            background-color: #f8fafc;
            border-color: #eef0f3;
        }
        .section-title {
            font-size: 0.8125rem;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.01em;
        }
        .eyebrow {
            font-size: 0.625rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .empty-state {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 2.5rem 1rem; text-align: center;
        }

        #notifDropdown {
            opacity: 0;
            transform: translateY(-6px) scale(0.97);
            pointer-events: none;
            transition: opacity 0.16s ease, transform 0.16s ease;
        }
        #notifDropdown.show {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        .notif-row.is-unread {
            background: #f0f4ff;
            border-left: 3px solid #6366f1;
        }
        .notif-row.is-unread:hover {
            background: #e8edff;
        }
        .notif-scroll::-webkit-scrollbar { width: 4px; }
        .notif-scroll::-webkit-scrollbar-track { background: transparent; }
        .notif-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }

        .sos-icon{
    animation:sosPulse 1.8s infinite;
}

@keyframes sosPulse{

0%{
transform:scale(1);
}

50%{
transform:scale(1.18);
}

100%{
transform:scale(1);
}

}

@keyframes dangerGlow{

0%{
box-shadow:0 0 0 rgba(239,68,68,.2);
}

50%{
box-shadow:0 0 20px rgba(239,68,68,.55);
}

100%{
box-shadow:0 0 0 rgba(239,68,68,.2);
}

}

.sos-active{

animation:dangerGlow 2s infinite;

}
    </style>
</head>

<body>

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <div class="flex-1 p-5 lg:p-8 max-w-[1400px] mx-auto w-full">

        <!-- ===================== HEADER ===================== -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-7">
            <div>
                <h1 class="text-xl lg:text-2xl font-bold text-slate-800 tracking-tight">
                    Welcome back, <span class="text-indigo-600"><?= htmlspecialchars($_SESSION['username']); ?></span> 👋
                </h1>
                <p class="text-slate-500 font-medium text-sm mt-0.5">Here's what's happening in your operations today</p>
            </div>

            <div class="flex items-center gap-3">
                <!-- 🔔 Universal Notifications Bell + Dropdown -->
                <?php include __DIR__ . "/../partials/notification_bell.php"; ?>

                <!-- 🔴 Urgent Tasks SOS Bell -->
                <a href="notifications.php" class="relative group">

<div class="flex items-center gap-2
            px-4 py-2.5
            rounded-2xl
            bg-gradient-to-r
            from-red-600
            to-red-500
            text-white
            shadow-lg
            shadow-red-200
            transition-all
            duration-300
            group-hover:scale-105
            group-hover:shadow-red-300
            <?= $urgent>0 ? 'sos-active' : '' ?>">

        <span class="material-icons-round text-xl sos-icon">
            notifications
        </span>

        <?php if($urgent>0): ?>
        <span
        class="absolute
               -top-2
               -right-2
               min-w-[22px]
               h-[22px]
               rounded-full
               bg-white
               text-red-600
               text-[10px]
               font-black
               flex
               items-center
               justify-center
               border-2
               border-red-600">
            <?= $showCount ?>
        </span>
        <?php endif; ?>

    </div>

</a>

                <!-- 👤 User Profile -->
                <div class="h-10 pl-1.5 pr-3.5 bg-white rounded-xl shadow-sm border border-slate-200 flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-[11px]">
                        <?= strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                    </div>
                    <div class="hidden md:block leading-tight">
                        <p class="text-[11px] font-bold text-slate-700">Operations Manager</p>
                        <p class="text-[9px] text-slate-400 font-semibold tracking-wide"><?= date("d M, Y"); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================== KPI CARDS ===================== -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5 mb-7">
            <?php foreach($cards as $c): ?>
            <div class="kpi-card surface p-4 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="icon-chip bg-<?= $c['color'] ?>-50 text-<?= $c['color'] ?>-600">
                            <span class="material-icons-round text-[18px]"><?= $c['icon'] ?></span>
                        </div>
                        <?php if(!empty($c['badge'])): ?>
                            <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md bg-purple-100 text-purple-700">
                                <?= $c['badge'] ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-2xl font-extrabold text-slate-800 leading-none"><?= $c['val'] ?></h2>
                    <p class="eyebrow mt-2"><?= $c['label'] ?></p>
                </div>
                <p class="text-[11px] text-slate-400 font-medium mt-1.5"><?= $c['sub'] ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ===================== 📅 DAILY TASK SECTION (DATE-WISE BREAKDOWN) ===================== -->
        <div class="surface p-6 mb-7">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-5 pb-4 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold">
                        <span class="material-icons-round text-xl">calendar_today</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-800">Daily Task History & Date Archive</h3>
                            <span class="text-xs font-bold text-slate-400">• <?= date('d M Y', strtotime($dashDate)) ?></span>
                            <?php if ($dashDate === date('Y-m-d')): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider">Today</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500">Pick any date to inspect tasks recorded on that date</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <a href="?dash_date=<?= date('Y-m-d') ?>" 
                       class="px-3 py-1.5 rounded-xl text-xs font-bold <?= $dashDate === date('Y-m-d') ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        Today
                    </a>
                    <a href="?dash_date=<?= date('Y-m-d', strtotime('-1 day')) ?>" 
                       class="px-3 py-1.5 rounded-xl text-xs font-bold <?= $dashDate === date('Y-m-d', strtotime('-1 day')) ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        Yesterday
                    </a>

                    <form method="GET" class="flex items-center m-0">
                        <input type="date" name="dash_date" value="<?= htmlspecialchars($dashDate) ?>"
                               onchange="this.form.submit()"
                               class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </form>

                    <a href="today_tasks.php?date=<?= $dashDate ?>" 
                       class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-900 text-white hover:bg-slate-800 flex items-center gap-1 transition-colors">
                        Full Date Hub <span class="material-icons-round text-sm">arrow_forward</span>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Date Total</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= $dailyStats['total'] ?> Tasks</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-amber-500">Assigned / In Work</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= ($dailyStats['assigned'] + $dailyStats['in_work']) ?> Active</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-emerald-500">Completed</span>
                    <p class="text-lg font-extrabold text-emerald-600"><?= $dailyStats['completed'] ?> Delivered</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-indigo-500">Total Volume</span>
                    <p class="text-lg font-extrabold text-indigo-600"><?= number_format($dailyStats['words']) ?> Words</p>
                </div>
            </div>

            <?php if (count($dailyTasks) > 0): ?>
            <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
                <?php foreach (array_slice($dailyTasks, 0, 8) as $dt): 
                    $clLink = getDriveClientLink($dt);
                    $opLink = getDriveOperationsLink($dt);
                ?>
                <div class="activity-row flex flex-col sm:flex-row sm:items-center justify-between p-3 border border-slate-100 rounded-xl gap-2">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                            <?= substr($dt['task_key'], -2) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($dt['task_name']) ?></p>
                            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wide">
                                <?= $dt['task_key'] ?> · <?= number_format($dt['word_count'] ?? 0) ?> words · Expert: <?= htmlspecialchars($dt['expert_name'] ?? 'Unassigned') ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 self-end sm:self-center flex-shrink-0">
                        <?php if (!empty($clLink)): ?>
                        <a href="<?= htmlspecialchars($clLink) ?>" target="_blank"
                           class="px-2 py-1 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded text-[10px] font-bold transition-all"
                           title="Direct Client Files Subfolder">
                            Client Files
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($opLink)): ?>
                        <a href="<?= htmlspecialchars($opLink) ?>" target="_blank"
                           class="px-2 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded text-[10px] font-bold transition-all"
                           title="Direct Operations Files Subfolder">
                            Ops Files
                        </a>
                        <?php endif; ?>
                        <span class="px-2 py-0.5 border text-[9px] font-bold rounded uppercase tracking-wider <?= opsStatusBadgeClass($dt['status']) ?>">
                            <?= str_replace('_', ' ', $dt['status']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($dailyTasks) > 8): ?>
            <div class="text-center pt-3 border-t border-slate-100 mt-2">
                <a href="today_tasks.php?date=<?= $dashDate ?>" class="text-xs font-bold text-indigo-600 hover:underline">
                    View all <?= count($dailyTasks) ?> tasks for this date →
                </a>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-center py-6 text-slate-400">
                <span class="material-icons-round text-3xl text-slate-300 mb-1">event_busy</span>
                <p class="text-xs font-semibold">No tasks recorded for <?= date('d M Y', strtotime($dashDate)) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===================== CHARTS ===================== -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-7">
            <div class="surface p-5">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="section-title flex items-center gap-2">
                        <span class="material-icons-round text-indigo-500 text-[16px]">donut_large</span> Task Distribution
                    </h3>
                </div>
                <?php if($hasStatusData): ?>
                    <div id="statusChart"></div>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="material-icons-round text-slate-200 text-4xl mb-2">pie_chart_outline</span>
                        <p class="text-xs font-semibold text-slate-400">No task data yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="surface p-5">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="section-title flex items-center gap-2">
                        <span class="material-icons-round text-emerald-500 text-[16px]">bar_chart</span> Efficiency Leaderboard
                    </h3>
                </div>
                <?php if($hasTeamData): ?>
                    <div id="teamChart"></div>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="material-icons-round text-slate-200 text-4xl mb-2">groups</span>
                        <p class="text-xs font-semibold text-slate-400">No completed tasks yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===================== TOP PERFORMER + RECENT ACTIVITY ===================== -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <div class="rounded-[18px] bg-gradient-to-br from-indigo-600 to-indigo-700 p-5 relative overflow-hidden shadow-sm">
                <span class="material-icons-round absolute -right-3 -bottom-3 text-[100px] text-white opacity-10">emoji_events</span>

                <div class="flex items-center gap-2 mb-4">
                    <span class="material-icons-round text-amber-300 text-[18px]">military_tech</span>
                    <h3 class="text-white font-bold text-sm">Top Performer</h3>
                </div>

                <?php if($topUser): ?>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-11 h-11 rounded-xl bg-white/15 border border-white/20 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                            <?= strtoupper(substr($topUser['name'], 0, 1)); ?>
                        </div>
                        <div class="min-w-0">
                            <h4 class="text-base font-bold text-white truncate"><?= htmlspecialchars($topUser['name']) ?></h4>
                            <span class="inline-block text-[9px] font-bold uppercase tracking-wider text-indigo-100 bg-white/10 px-2 py-0.5 rounded-md mt-0.5">Current Leader</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 py-2.5 px-3.5 bg-white/10 rounded-xl border border-white/10">
                        <span class="text-white font-black text-xl leading-none"><?= $topUser['done'] ?></span>
                        <span class="text-indigo-100 text-[10px] font-bold uppercase leading-tight">Tasks<br>Finished</span>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="material-icons-round text-white/40 text-4xl mb-2">emoji_events</span>
                        <p class="text-xs font-semibold text-indigo-100">Waiting for data...</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-2 surface p-5">
                <h3 class="section-title flex items-center gap-2 mb-3">
                    <span class="material-icons-round text-slate-400 text-[16px]">history</span> Live Feed
                </h3>

                <?php if(count($recent) > 0): ?>
                <div class="space-y-1">
                    <?php foreach($recent as $r): ?>
                    <div class="activity-row flex items-center justify-between p-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center font-bold text-[11px] text-indigo-600 flex-shrink-0">
                                <?= substr($r['task_key'], -2) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[13px] font-bold text-slate-700 leading-tight truncate"><?= htmlspecialchars($r['task_name']) ?></p>
                                <p class="text-[10px] font-semibold text-slate-400 mt-0.5 uppercase tracking-wide"><?= $r['task_key'] ?> · <?= htmlspecialchars($r['user_name'] ?? 'Queue') ?></p>
                            </div>
                        </div>
                        <span class="flex-shrink-0 px-2.5 py-1 border text-[9px] font-bold rounded-lg uppercase tracking-wider <?= opsStatusBadgeClass($r['status']) ?>">
                            <?= str_replace('_', ' ', $r['status']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="material-icons-round text-slate-200 text-4xl mb-2">inbox</span>
                        <p class="text-xs font-semibold text-slate-400">No recent activity</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

<!-- ===== NOTIFICATION DROPDOWN TOGGLE ===== -->
<script>
const notifBtn = document.getElementById("notifBtn");
const notifBox = document.getElementById("notifDropdown");

notifBtn.addEventListener("click", function(e) {
    e.stopPropagation();
    notifBox.classList.toggle("show");
});

document.addEventListener("click", function(e) {
    if (!notifBox.contains(e.target)) {
        notifBox.classList.remove("show");
    }
});

notifBox.addEventListener("click", function(e) {
    e.stopPropagation();
});
</script>

<!-- 🔔 LOGIN TOAST -->
<?php if($unread > 0): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        toast: true,
        position: "top-end",
        timer: 5000,
        timerProgressBar: true,
        showConfirmButton: false,
        icon: "info",
        title: "You have <?= $unread ?> new notification<?= $unread > 1 ? 's' : '' ?>."
    });
});
</script>
<?php endif; ?>

<!-- 🔴 EXISTING: Urgent tasks alert (UNCHANGED) -->
<?php if($urgent > 0): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        icon: 'warning',
        title: 'Immediate Action Required',
        text: 'There are <?= $urgent ?> urgent tasks pending your assignment.',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-2xl' }
    });
});
</script>
<?php endif; ?>

<?php if($hasStatusData): ?>
<script>
new ApexCharts(document.querySelector("#statusChart"), {
    chart: { type: 'donut', height: 280, fontFamily: 'Plus Jakarta Sans', toolbar: { show: false } },
    series: [
        <?= $statusData['assigned'] ?? 0 ?>,
        <?= $statusData['in_progress'] ?? 0 ?>,
        <?= $statusData['completed'] ?? 0 ?>,
        <?= $statusData['rework'] ?? 0 ?>
    ],
    labels: ['Assigned', 'In Progress', 'Completed', 'Rework'],
    colors: ['#f59e0b', '#6366f1', '#10b981', '#f43f5e'],
    legend: {
        position: 'bottom',
        fontSize: '11px',
        fontWeight: 600,
        labels: { colors: '#475569' },
        markers: { width: 8, height: 8, radius: 2 },
        itemMargin: { horizontal: 8, vertical: 4 }
    },
    stroke: { show: true, width: 2, colors: ['#fff'] },
    dataLabels: { enabled: false },
    grid: { padding: { top: 0, bottom: 0 } },
    plotOptions: {
        pie: {
            donut: {
                size: '72%',
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Total',
                        fontSize: '11px',
                        fontWeight: 700,
                        color: '#94a3b8',
                        formatter: function (w) {
                            return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                        }
                    },
                    value: { fontSize: '20px', fontWeight: 800, color: '#1e293b' }
                }
            }
        }
    }
}).render();
</script>
<?php endif; ?>

<?php if($hasTeamData): ?>
<script>
new ApexCharts(document.querySelector("#teamChart"), {
    chart: { type: 'bar', height: 280, fontFamily: 'Plus Jakarta Sans', toolbar: { show: false } },
    series: [{ name: 'Tasks Completed', data: [<?= implode(',', array_column($teamPerf,'done')) ?>] }],
    xaxis: {
        categories: [<?= '"' . implode('","', array_map('htmlspecialchars', array_column($teamPerf,'name'))) . '"' ?>],
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { fontSize: '11px', colors: '#64748b', fontWeight: 600 } }
    },
    yaxis: {
        labels: { style: { fontSize: '11px', colors: '#94a3b8' } }
    },
    colors: ['#6366f1'],
    grid: { borderColor: '#f1f5f9', strokeDashArray: 3 },
    plotOptions: { bar: { borderRadius: 8, columnWidth: '45%', distributed: false } },
    dataLabels: { enabled: false }
}).render();
</script>
<?php endif; ?>

</body>
</html>