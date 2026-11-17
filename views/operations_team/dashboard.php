<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
require_once "../../app/services/TaskWorkflowService.php";

 $db = (new Database())->connect();
 $user = $_SESSION['user_id'];

// Auto-withdraw any tasks assigned for 30+ days without submission
TaskWorkflowService::autoWithdrawStaleTasks($db);

/* 📅 Daily Task Section (Date-Wise) */
$dashDate = $_GET['dash_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
    $dashDate = date('Y-m-d');
}

/* 🔍 GLOBAL TASK KEY SEARCH (across all assigned/forwarded tasks) */
$globalSearchKey = trim($_GET['global_key'] ?? '');
$searchTask = null;
$searchError = null;
if (!empty($globalSearchKey)) {
    $stmtSearch = $db->prepare("
        SELECT t.*, u.name as bde_name 
        FROM tasks t
        LEFT JOIN users u ON t.created_by = u.id
        WHERE (t.assigned_to = :u OR t.forwarded_feedback_to = :u)
          AND (t.task_key = :exact_key OR t.task_key LIKE :like_key)
        ORDER BY CASE WHEN t.task_key = :exact_key THEN 0 ELSE 1 END, t.id DESC
        LIMIT 1
    ");
    $stmtSearch->execute([
        ':u'         => $user,
        ':exact_key' => $globalSearchKey,
        ':like_key'  => "%{$globalSearchKey}%"
    ]);
    $searchTask = $stmtSearch->fetch(PDO::FETCH_ASSOC);

    if (!$searchTask) {
        $searchError = "No assignment found matching key \"{$globalSearchKey}\" in your workspace.";
    }
}

$stmtDaily = $db->prepare("
    SELECT t.*
    FROM tasks t
    WHERE t.assigned_to = :user
    AND (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)
    ORDER BY t.id DESC
");
$stmtDaily->execute([':user' => $user, ':dt' => $dashDate]);
$dailyTasks = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

$dailyStats = [
    'total'     => count($dailyTasks),
    'completed' => 0,
    'in_work'   => 0,
    'qc'        => 0,
    'words'     => 0
];
foreach ($dailyTasks as $dt) {
    $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
    if ($dt['current_stage'] === 'qc') $dailyStats['qc']++;
    elseif ($dt['status'] === 'completed') $dailyStats['completed']++;
    else $dailyStats['in_work']++;
}

/* ================= STATS ================= */
 $total      = $db->query("SELECT COUNT(*) FROM tasks WHERE assigned_to=$user")->fetchColumn();
 $pending    = $db->query("SELECT COUNT(*) FROM tasks WHERE assigned_to=$user AND status='assigned'")->fetchColumn();
 $inprogress = $db->query("SELECT COUNT(*) FROM tasks WHERE assigned_to=$user AND status='in_progress'")->fetchColumn();
 $completed  = $db->query("SELECT COUNT(*) FROM tasks WHERE assigned_to=$user AND status='completed'")->fetchColumn();
 $urgent     = $db->query("SELECT COUNT(*) FROM tasks WHERE assigned_to=$user AND urgent='yes' AND status != 'completed'")->fetchColumn();
 $totalWords = $db->query("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE assigned_to=$user AND status='completed'")->fetchColumn();
 $currentMonth = date('Y-m');
 $stmtMonthWords = $db->prepare("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE assigned_to = :u AND status='completed' AND DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = :currMonth");
 $stmtMonthWords->execute([':u' => $user, ':currMonth' => $currentMonth]);
 $monthlyWords = (int)$stmtMonthWords->fetchColumn();

/* 🔔 NOTIFICATIONS */
 $stmtNotify = $db->prepare("
    SELECT *
    FROM notifications
    WHERE user_id = :user
    ORDER BY created_at DESC
    LIMIT 10
");
 $stmtNotify->execute([
    ':user' => $user
]);
 $notifications = $stmtNotify->fetchAll(PDO::FETCH_ASSOC);

 $stmtUnread = $db->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id = :user
    AND is_read = 0
");
 $stmtUnread->execute([
    ':user' => $user
]);
 $unread = $stmtUnread->fetchColumn();

/* 📊 STATUS DATA */
 $statusData = $db->query("SELECT status, COUNT(*) as total FROM tasks WHERE assigned_to=$user GROUP BY status")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);

/* 🕒 RECENT WORK */
 $recent = $db->query("
    SELECT t.*
    FROM tasks t
    WHERE t.assigned_to=$user
    ORDER BY t.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ================= HELPERS ================= */
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

function notifIcon($type) {
    return match($type) {
        'urgent_task'      => ['icon' => 'priority_high', 'color' => 'text-red-600',    'bg' => 'bg-red-50'],
        'task_assigned'    => ['icon' => 'assignment',    'color' => 'text-indigo-600', 'bg' => 'bg-indigo-50'],
        'feedback'         => ['icon' => 'rate_review',   'color' => 'text-emerald-600','bg' => 'bg-emerald-50'],
        'additional_files' => ['icon' => 'folder',        'color' => 'text-blue-600',   'bg' => 'bg-blue-50'],
        'task_rework'      => ['icon' => 'refresh',       'color' => 'text-rose-600',   'bg' => 'bg-rose-50'],
        'task_completed'   => ['icon' => 'verified',      'color' => 'text-emerald-600','bg' => 'bg-emerald-50'],
        default            => ['icon' => 'notifications', 'color' => 'text-slate-500',  'bg' => 'bg-slate-50']
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expert Workspace | Team Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .stat-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid #f1f5f9; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05); }
        .bg-indigo-glow { background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }

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

        .badge-pulse {
            animation: bpulse 2s infinite;
        }
        @keyframes bpulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 overflow-y-auto">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 max-w-7xl mx-auto gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">
                    Expert Workspace: <span class="text-indigo-600"><?= $_SESSION['username']; ?></span> 🚀
                </h1>
                <p class="text-slate-500 mt-1 font-medium italic">Monitor your progress, search assignments, and track daily milestones.</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <!-- 🔍 GLOBAL TASK KEY SEARCH FORM -->
                <form action="dashboard.php" method="GET" class="flex items-center gap-2 bg-white px-3 py-2 rounded-2xl shadow-sm border border-slate-200">
                    <span class="material-icons-round text-slate-400 text-sm">search</span>
                    <input type="text" name="global_key" value="<?= htmlspecialchars($globalSearchKey) ?>" 
                           placeholder="Search Task Key (e.g. TASK-123)..." 
                           class="bg-transparent border-none text-xs font-bold text-slate-700 outline-none w-52 sm:w-60"
                           required>
                    <button type="submit" class="bg-indigo-600 hover:bg-slate-900 text-white px-3 py-1.5 rounded-xl text-xs font-black transition-all flex items-center gap-1 shadow-xs">
                        Find <span class="material-icons-round text-xs">arrow_forward</span>
                    </button>
                    <?php if(!empty($globalSearchKey)): ?>
                        <a href="dashboard.php" class="p-1 hover:text-rose-600 text-slate-400" title="Clear Search">
                            <span class="material-icons-round text-sm">close</span>
                        </a>
                    <?php endif; ?>
                </form>

                <!-- 🔔 UNIVERSAL NOTIFICATION BELL + DROPDOWN -->
                <?php include __DIR__ . "/../partials/notification_bell.php"; ?>

                <!-- 📅 DATE CARD -->
                <div class="bg-white px-5 py-3 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-4">
                    <div class="h-10 w-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600">
                        <span class="material-icons-round">calendar_today</span>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Shift Date</p>
                        <p class="text-sm font-bold text-slate-700 mt-1"><?= date("d M, Y • h:i A"); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 🔍 GLOBAL SEARCH RESULTS CARD (Appears only when searching) -->
        <?php if(!empty($globalSearchKey)): ?>
            <div class="max-w-7xl mx-auto mb-10">
                <?php if($searchTask): 
                    $sClientDrive = getDriveClientLink($searchTask);
                    $sOpsDrive    = getDriveOperationsLink($searchTask);
                ?>
                    <div class="bg-white rounded-[2.5rem] p-7 shadow-lg border-2 border-indigo-200 relative overflow-hidden">
                        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 pb-5 border-b border-slate-100">
                            <div>
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="bg-indigo-600 text-white text-xs font-black uppercase tracking-wider px-3 py-1 rounded-xl shadow-xs">
                                        <?= htmlspecialchars($searchTask['task_key']) ?>
                                    </span>
                                    <span class="text-xs font-extrabold uppercase px-2.5 py-0.5 rounded-lg <?= $searchTask['current_stage'] === 'qc' ? 'bg-amber-100 text-amber-800' : ($searchTask['status'] === 'completed' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700') ?>">
                                        Stage: <?= ucfirst($searchTask['current_stage']) ?> (<?= ucfirst(str_replace('_', ' ', $searchTask['status'])) ?>)
                                    </span>
                                    <?php if($searchTask['urgent'] === 'yes'): ?>
                                        <span class="bg-rose-100 text-rose-700 text-[10px] font-black uppercase px-2 py-0.5 rounded-md animate-pulse">
                                            Urgent
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-xl font-black text-slate-800"><?= htmlspecialchars($searchTask['task_name']) ?></h3>
                                <p class="text-xs text-slate-400 mt-1">
                                    Client: <strong class="text-emerald-600"><?= htmlspecialchars($searchTask['client_name'] ?: 'N/A') ?></strong> • 
                                    Subject: <strong class="text-slate-600"><?= htmlspecialchars($searchTask['subject'] ?: 'N/A') ?></strong> • 
                                    Word Count: <strong class="text-slate-800"><?= number_format($searchTask['word_count']) ?></strong> • 
                                    Deadline: <strong class="text-rose-600"><?= date('d M Y, h:i A', strtotime($searchTask['deadline'])) ?></strong>
                                </p>
                            </div>

                            <!-- Quick Action Links -->
                            <div class="flex flex-wrap items-center gap-2.5">
                                <?php if(!empty($sClientDrive)): ?>
                                    <a href="<?= htmlspecialchars($sClientDrive) ?>" target="_blank"
                                       class="px-4 py-2 bg-indigo-50 hover:bg-indigo-600 hover:text-white text-indigo-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 border border-indigo-100">
                                        <span class="material-icons-round text-sm">folder_open</span> Client Files
                                    </a>
                                <?php endif; ?>
                                <?php if(!empty($sOpsDrive)): ?>
                                    <a href="<?= htmlspecialchars($sOpsDrive) ?>" target="_blank"
                                       class="px-4 py-2 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 border border-emerald-100">
                                        <span class="material-icons-round text-sm">folder_shared</span> Operations Files
                                    </a>
                                <?php endif; ?>
                                <a href="work_history.php?search=<?= urlencode($searchTask['task_key']) ?>" 
                                   class="px-4 py-2 bg-slate-900 hover:bg-indigo-600 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs">
                                    <span>Open In Work History</span>
                                    <span class="material-icons-round text-sm">open_in_new</span>
                                </a>
                                <a href="dashboard.php" class="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-100" title="Close Result">
                                    <span class="material-icons-round text-sm">close</span>
                                </a>
                            </div>
                        </div>

                        <!-- Notes Preview -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5">
                            <?php if(!empty($searchTask['instructions'])): ?>
                                <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-100">
                                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider mb-1 flex items-center gap-1">
                                        <span class="material-icons-round text-xs">description</span> Client Brief
                                    </p>
                                    <p class="text-xs text-slate-700 line-clamp-3 italic"><?= htmlspecialchars($searchTask['instructions']) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($searchTask['om_notes'])): ?>
                                <div class="bg-blue-50/70 p-3.5 rounded-2xl border border-blue-100">
                                    <p class="text-[10px] font-black uppercase text-blue-700 tracking-wider mb-1 flex items-center gap-1">
                                        <span class="material-icons-round text-xs">sticky_note_2</span> Manager Note
                                    </p>
                                    <p class="text-xs text-blue-900 line-clamp-3 font-semibold"><?= htmlspecialchars($searchTask['om_notes']) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($searchTask['qc_notes'])): ?>
                                <div class="bg-amber-50/70 p-3.5 rounded-2xl border border-amber-200">
                                    <p class="text-[10px] font-black uppercase text-amber-800 tracking-wider mb-1 flex items-center gap-1">
                                        <span class="material-icons-round text-xs">fact_check</span> QC Feedback
                                    </p>
                                    <p class="text-xs text-amber-950 line-clamp-3 font-bold"><?= htmlspecialchars($searchTask['qc_notes']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-rose-50 border border-rose-200 p-6 rounded-[2rem] flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="material-icons-round text-rose-600 text-2xl">search_off</span>
                            <div>
                                <h4 class="text-sm font-bold text-rose-900">Task Not Found</h4>
                                <p class="text-xs text-rose-700"><?= htmlspecialchars($searchError) ?></p>
                            </div>
                        </div>
                        <a href="dashboard.php" class="text-xs font-bold text-rose-700 hover:text-rose-900 underline">Clear</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-6 mb-10 max-w-7xl mx-auto">
            
            <div class="stat-card bg-white p-6 rounded-[2rem] flex flex-col justify-between">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Total Load</span>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter"><?= $total ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem] flex flex-col justify-between border-l-4 border-l-amber-400">
                <span class="text-[10px] font-black text-amber-500 uppercase tracking-widest mb-4">Pending</span>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter"><?= $pending ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem] flex flex-col justify-between border-l-4 border-l-indigo-500">
                <span class="text-[10px] font-black text-indigo-500 uppercase tracking-widest mb-4">In Work</span>
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter"><?= $inprogress ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem] flex flex-col justify-between border-l-4 border-l-emerald-500">
                <span class="text-[10px] font-black text-emerald-500 uppercase tracking-widest mb-4">Completed</span>
                <h2 class="text-3xl font-black text-emerald-600 tracking-tighter"><?= $completed ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem] flex flex-col justify-between border-l-4 border-l-rose-500">
                <span class="text-[10px] font-black text-rose-500 uppercase tracking-widest mb-4">Urgent</span>
                <h2 class="text-3xl font-black text-rose-600 tracking-tighter <?= $urgent > 0 ? 'animate-pulse' : '' ?>"><?= $urgent ?></h2>
            </div>

            <div class="stat-card bg-indigo-glow p-6 rounded-[2rem] flex flex-col justify-between shadow-xl shadow-indigo-100">
                <div>
                    <span class="text-[10px] font-black text-indigo-200 uppercase tracking-widest mb-1 block">Total Words</span>
                    <h2 class="text-2xl font-black text-white tracking-tight"><?= number_format($totalWords) ?></h2>
                </div>
                <div class="mt-2 pt-2 border-t border-indigo-400/30 flex items-center justify-between text-[11px]">
                    <span class="text-indigo-200 font-bold"><?= date('M') ?> Month:</span>
                    <span class="font-extrabold text-white bg-white/20 px-2 py-0.5 rounded-full"><?= number_format($monthlyWords) ?></span>
                </div>
            </div>
        </div>

        <!-- ===================== 📅 DAILY TASK SECTION (DATE-WISE BREAKDOWN) ===================== -->
        <div class="bg-white p-6 sm:p-8 rounded-[2.5rem] shadow-sm border border-slate-100 mb-10 max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 pb-4 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold">
                        <span class="material-icons-round text-xl">calendar_today</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-800">Daily Task Log & History</h3>
                            <span class="text-xs font-bold text-slate-400">• <?= date('d M Y', strtotime($dashDate)) ?></span>
                            <?php if ($dashDate === date('Y-m-d')): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider">Today</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500">View tasks assigned to you on any date</p>
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
                        Full Daily Hub <span class="material-icons-round text-sm">arrow_forward</span>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Total Load</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= $dailyStats['total'] ?> Tasks</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-amber-500">In Work</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= $dailyStats['in_work'] ?> Active</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-purple-500">Under QC</span>
                    <p class="text-lg font-extrabold text-purple-600"><?= $dailyStats['qc'] ?> In Review</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-emerald-500">Completed</span>
                    <p class="text-lg font-extrabold text-emerald-600"><?= $dailyStats['completed'] ?> Finished</p>
                </div>
            </div>

            <?php if (count($dailyTasks) > 0): ?>
            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                <?php foreach (array_slice($dailyTasks, 0, 6) as $dt): 
                    $clLink = getDriveClientLink($dt);
                    $opLink = getDriveOperationsLink($dt);
                ?>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3.5 border border-slate-100 rounded-2xl hover:bg-slate-50/60 transition-colors gap-2">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                            <?= substr($dt['task_key'], -2) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($dt['task_name']) ?></p>
                            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wide">
                                <?= $dt['task_key'] ?> · <?= number_format($dt['word_count'] ?? 0) ?> words · Client: <?= htmlspecialchars($dt['client_name'] ?? 'N/A') ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 self-end sm:self-center flex-shrink-0">
                        <?php if (!empty($clLink)): ?>
                        <a href="<?= htmlspecialchars($clLink) ?>" target="_blank"
                           class="px-2 py-1 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-[10px] font-bold transition-all">
                            Client Files
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($opLink)): ?>
                        <a href="<?= htmlspecialchars($opLink) ?>" target="_blank"
                           class="px-2 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all">
                            Ops Files
                        </a>
                        <?php endif; ?>
                        <span class="px-2.5 py-0.5 border text-[9px] font-bold rounded-lg uppercase tracking-wider <?= $dt['current_stage'] === 'qc' ? 'bg-purple-50 text-purple-700 border-purple-200' : 'bg-slate-100 text-slate-600 border-slate-200' ?>">
                            <?= $dt['current_stage'] === 'qc' ? 'Under QC' : str_replace('_', ' ', $dt['status']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($dailyTasks) > 6): ?>
            <div class="text-center pt-3 border-t border-slate-100 mt-2">
                <a href="today_tasks.php?date=<?= $dashDate ?>" class="text-xs font-bold text-indigo-600 hover:underline">
                    View all <?= count($dailyTasks) ?> tasks for this date →
                </a>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-center py-6 text-slate-400">
                <span class="material-icons-round text-3xl text-slate-300 mb-1">event_available</span>
                <p class="text-xs font-semibold">No tasks assigned to you on <?= date('d M Y', strtotime($dashDate)) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10 max-w-7xl mx-auto">
            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2">
                    <span class="material-icons-round text-indigo-500">analytics</span> Work Distribution
                </h3>
                <div id="statusChart"></div>
            </div>

            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2">
                    <span class="material-icons-round text-purple-500">show_chart</span> Productivity Index
                </h3>
                <div id="productivityChart"></div>
            </div>
        </div>

        <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden max-w-7xl mx-auto">
            <div class="p-8 border-b border-slate-50 flex justify-between items-center">
                <h3 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="material-icons-round text-slate-400">update</span> Recent Actions
                </h3>
                <a href="work_history.php" class="text-xs font-black text-indigo-600 uppercase tracking-widest hover:underline">Full History →</a>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[11px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50">
                            <th class="px-8 py-5">Task Identity</th>
                            <th class="px-8 py-5">Words</th>
                            <th class="px-8 py-5 text-center">Files</th>
                            <th class="px-8 py-5">Internal Notes</th>
                            <th class="px-8 py-5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($recent as $r): ?>
                        <tr class="group hover:bg-slate-50/50 transition-all">
                            <td class="px-8 py-5">
                                <span class="font-bold text-slate-700 text-sm italic block uppercase tracking-tighter leading-none mb-1"><?= $r['task_key'] ?></span>
                                <span class="text-[10px] font-medium text-slate-400"><?= htmlspecialchars($r['task_name']) ?></span>
                            </td>
                            <td class="px-8 py-5 font-bold text-slate-600 text-sm"><?= number_format($r['word_count']) ?></td>
                            <td class="px-8 py-5">
                                <div class="flex items-center justify-center gap-2">
                                    <?php 
                                    $rClLink = getDriveClientLink($r); 
                                    $rOpLink = getDriveOperationsLink($r);
                                    ?>
                                    <?php if(!empty($rClLink)): ?>
                                        <a href="<?= htmlspecialchars($rClLink) ?>" target="_blank"
                                           class="h-8 w-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center hover:bg-blue-600 hover:text-white transition-all shadow-sm"
                                           title="Client Files (Drive)">
                                            <span class="material-icons-round text-sm">folder_open</span>
                                        </a>
                                    <?php elseif(!empty($r['file_path']) && file_exists('../../' . $r['file_path'])): ?>
                                        <a href="../../<?= htmlspecialchars($r['file_path']) ?>" download
                                           class="h-8 w-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center hover:bg-blue-600 hover:text-white transition-all shadow-sm"
                                           title="Download Client Brief">
                                            <span class="material-icons-round text-sm">download</span>
                                        </a>
                                    <?php endif; ?>

                                    <?php if(!empty($rOpLink)): ?>
                                        <a href="<?= htmlspecialchars($rOpLink) ?>" target="_blank"
                                           class="h-8 w-8 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center hover:bg-emerald-600 hover:text-white transition-all shadow-sm"
                                           title="Operations Files (Drive)">
                                            <span class="material-icons-round text-sm">inventory_2</span>
                                        </a>
                                    <?php elseif(!empty($r['team_file']) && file_exists('../../' . $r['team_file'])): ?>
                                        <a href="../../<?= htmlspecialchars($r['team_file']) ?>" download
                                           class="h-8 w-8 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center hover:bg-emerald-600 hover:text-white transition-all shadow-sm"
                                           title="Download Operations Solution">
                                            <span class="material-icons-round text-sm">download</span>
                                        </a>
                                    <?php endif; ?>

                                    <?php if(empty($rClLink) && empty($rOpLink) && empty($r['file_path']) && empty($r['team_file'])): ?>
                                        <span class="text-[10px] font-bold text-slate-400 uppercase italic">No Files</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-8 py-5">
                                <div class="max-w-[150px] truncate text-xs text-slate-400 italic">
                                    QC: <?= $r['qc_notes'] ?: 'No notes' ?>
                                </div>
                            </td>
                            <td class="px-8 py-5 text-right">
                                <?php 
                                    $col = [
                                        'assigned' => 'amber',
                                        'in_progress' => 'indigo',
                                        'completed' => 'emerald',
                                        'rework' => 'rose'
                                    ][$r['status']] ?? 'slate';
                                ?>
                                <span class="px-3 py-1 bg-<?= $col ?>-50 text-<?= $col ?>-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-<?= $col ?>-100">
                                    <?= $r['status'] ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
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

<script>
new ApexCharts(document.querySelector("#statusChart"), {
    chart: { type: 'donut', height: 320, fontFamily: 'Plus Jakarta Sans' },
    series: [
        <?= $statusData['assigned'] ?? 0 ?>,
        <?= $statusData['in_progress'] ?? 0 ?>,
        <?= $statusData['completed'] ?? 0 ?>,
        <?= $statusData['rework'] ?? 0 ?>
    ],
    labels: ['Assigned', 'In Progress', 'Completed', 'Rework'],
    colors: ['#f59e0b', '#6366f1', '#10b981', '#f43f5e'],
    stroke: { show: false },
    dataLabels: { enabled: false },
    legend: { position: 'bottom', fontWeight: 700 },
    plotOptions: { pie: { donut: { size: '75%' } } }
}).render();

new ApexCharts(document.querySelector("#productivityChart"), {
    chart: { type: 'bar', height: 320, fontFamily: 'Plus Jakarta Sans', toolbar: {show: false} },
    series: [{ name: 'Output', data: [<?= $totalWords ?>] }],
    xaxis: { categories: ['Total Effort'], axisBorder: {show: false} },
    colors: ['#8b5cf6'],
    plotOptions: { bar: { borderRadius: 12, columnWidth: '35%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: '#f1f5f9' }
}).render();
</script>

</body>
</html>