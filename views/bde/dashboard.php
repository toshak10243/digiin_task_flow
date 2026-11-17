<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ⬇️ .ENV LOAD KARNE KE LIYE ZAROORI HAI ⬇️
require_once "../../bootstrap/app.php";

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bde');

require_once "../../app/config/database.php";

date_default_timezone_set('Asia/Kolkata');
 $db = (new Database())->connect();
 $user = $_SESSION['user_id'];

/* ===== CURRENT USER (for the greeting) ===== */
 $stmtUser = $db->prepare("SELECT name FROM users WHERE id = :user");
 $stmtUser->execute([':user' => $user]);
 $currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
 $userFirstName = $currentUser && !empty($currentUser['name']) ? explode(' ', trim($currentUser['name']))[0] : 'there';

 $hourNow = (int) date('G');
if ($hourNow < 12) { $greeting = "Good morning"; }
elseif ($hourNow < 17) { $greeting = "Good afternoon"; }
else { $greeting = "Good evening"; }

/* 📅 Daily Task Section (Date-Wise) */
$dashDate = $_GET['dash_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
    $dashDate = date('Y-m-d');
}

$stmtDaily = $db->prepare("
    SELECT t.*, expert.name AS expert_name
    FROM tasks t
    LEFT JOIN users expert ON t.assigned_to = expert.id
    WHERE t.created_by = :user
    AND (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)
    ORDER BY t.id DESC
");
$stmtDaily->execute([':user' => $user, ':dt' => $dashDate]);
$dailyTasks = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

$dailyStats = [
    'total'     => count($dailyTasks),
    'completed' => 0,
    'working'   => 0,
    'new'       => 0,
    'words'     => 0,
    'urgent'    => 0
];
foreach ($dailyTasks as $dt) {
    $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
    if (($dt['urgent'] ?? '') === 'yes') $dailyStats['urgent']++;
    if ($dt['status'] === 'completed') $dailyStats['completed']++;
    elseif ($dt['status'] === 'new') $dailyStats['new']++;
    else $dailyStats['working']++;
}

/* ===== SECURE STATS (Prepared Statements) ===== */
 $statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'rework' THEN 1 ELSE 0 END) as rework,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN urgent = 'yes' AND status != 'completed' THEN 1 ELSE 0 END) as urgent,
    SUM(CASE WHEN deadline < NOW() AND status != 'completed' THEN 1 ELSE 0 END) as overdue,
    COALESCE(SUM(word_count), 0) as total_words,
    COALESCE(SUM(CASE WHEN DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m') THEN word_count ELSE 0 END), 0) as monthly_words
FROM tasks WHERE created_by = :user";

 $stmt = $db->prepare($statsQuery);
 $stmt->execute([':user' => $user]);
 $stats = $stmt->fetch(PDO::FETCH_ASSOC);

/* ===== PIPELINE STAGE BREAKDOWN ===== */
 $stageOrder  = ['new', 'assigned', 'in_progress', 'rework', 'approved', 'completed'];
 $stageLabels = [
    'new'         => 'New',
    'assigned'    => 'Assigned',
    'in_progress' => 'Working',
    'rework'      => 'Rework',
    'approved'    => 'Approved',
    'completed'   => 'Completed',
];
 $stageColors = [
    'new'         => '#a1a1aa',
    'assigned'    => '#f2a93b',
    'in_progress' => '#3b6cf6',
    'rework'      => '#e11d48',
    'approved'    => '#7c5cf0',
    'completed'   => '#0f9d68',
];
 $stageCounts = array_fill_keys($stageOrder, 0);

 $stageQuery = "SELECT status, COUNT(*) as cnt FROM tasks WHERE created_by = :user GROUP BY status";
 $stmtStage = $db->prepare($stageQuery);
 $stmtStage->execute([':user' => $user]);
foreach ($stmtStage->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($stageCounts[$row['status']])) {
        $stageCounts[$row['status']] = (int) $row['cnt'];
    }
}
 $pipelineTotal = array_sum($stageCounts) ?: 1;

/* ===== RECENT ACTIVITY ===== */
 $recentQuery = "SELECT task_key, task_name, status, deadline, word_count, urgent, task_created_at 
                  FROM tasks WHERE created_by = :user 
                  ORDER BY id DESC LIMIT 6";
 $stmtRecent = $db->prepare($recentQuery);
 $stmtRecent->execute([':user' => $user]);
 $recent = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

/* ===== NEEDS ATTENTION ===== */
 $attentionQuery = "SELECT task_key, task_name, deadline, urgent, status 
                     FROM tasks 
                     WHERE created_by = :user AND status != 'completed' AND (deadline < NOW() OR urgent = 'yes')
                     ORDER BY deadline ASC LIMIT 5";
 $stmtAttention = $db->prepare($attentionQuery);
 $stmtAttention->execute([':user' => $user]);
 $attention = $stmtAttention->fetchAll(PDO::FETCH_ASSOC);

/* ===== NOTIFICATIONS ===== */
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
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = :user
    AND is_read = 0
");
 $stmtUnread->execute([
    ':user' => $user
]);
 $unreadCount = $stmtUnread->fetch(PDO::FETCH_ASSOC)['total'];

/* ===== Helper: human-readable relative time ===== */
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
<title>BDE Console | Digiink Workflow</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

<style>
    :root {
        --navy-900: #0b1420;
        --navy-800: #16232f;
        --gold-500: #eba940;
        --gold-600: #d99321;
        --gold-100: #fdf3e0;
        --ink-blue: #3b6cf6;
        --ink-blue-100: #e8edfe;
        --emerald: #0f9d68;
        --emerald-100: #e2f6ee;
        --rose: #e11d48;
        --rose-100: #fde8ec;
        --violet: #7c5cf0;
        --violet-100: #efeafd;
        --slate-bg: #f5f6f8;
        --border: #e6e8eb;
    }

    * { font-family: 'Inter', sans-serif; }
    body { background-color: var(--slate-bg); }
    .font-display { font-family: 'Sora', sans-serif; }
    .font-mono-num { font-family: 'JetBrains Mono', monospace; }

    .data-table { border-collapse: collapse; width: 100%; }
    .data-table th, .data-table td { 
        border-bottom: 1px solid var(--border); 
        padding: 0.65rem 0.8rem; 
        font-size: 0.75rem; 
        text-align: left;
    }
    .data-table thead { background-color: #fafafa; }
    .data-table thead th { 
        font-weight: 600; color: #71717a; text-transform: uppercase; 
        letter-spacing: 0.06em; font-size: 0.625rem; border-bottom: 1px solid var(--border); 
    }
    .data-table tbody tr { transition: background-color 0.12s ease; }
    .data-table tbody tr:hover { background-color: #fafafa; }

    .chip { 
        display: inline-flex; align-items: center; gap: 0.25rem; 
        padding: 0.15rem 0.55rem; border-radius: 0.25rem; 
        font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; 
        border-left: 3px solid; white-space: nowrap;
    }
    .chip-new { background: #f4f4f5; color: #52525b; border-color: #a1a1aa; }
    .chip-assigned { background: var(--gold-100); color: #92400e; border-color: var(--gold-500); }
    .chip-in_progress { background: var(--ink-blue-100); color: #1e40af; border-color: var(--ink-blue); }
    .chip-completed { background: var(--emerald-100); color: #065f46; border-color: var(--emerald); }
    .chip-rework { background: var(--rose-100); color: #9f1239; border-color: var(--rose); }
    .chip-approved { background: var(--violet-100); color: #5b21b6; border-color: var(--violet); }

    .kpi-card { background: #fff; border: 1px solid var(--border); border-radius: 0.6rem; padding: 0.9rem 1rem; box-shadow: 0 1px 2px rgba(15,23,42,0.03); }
    .kpi-icon { width: 2rem; height: 2rem; border-radius: 0.5rem; display: inline-flex; align-items: center; justify-content: center; }

    .ink-rail { position: relative; height: 14px; border-radius: 999px; overflow: hidden; background: #f0f1f3; display: flex; }
    .ink-seg { height: 100%; position: relative; }
    .ink-seg.is-active::after {
        content: '';
        position: absolute; inset: 0;
        background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,0.45) 50%, transparent 70%);
        background-size: 200% 100%;
        animation: ink-shimmer 2.4s ease-in-out infinite;
    }
    @keyframes ink-shimmer {
        0% { background-position: 150% 0; }
        100% { background-position: -50% 0; }
    }
    @media (prefers-reduced-motion: reduce) {
        .ink-seg.is-active::after { animation: none; }
    }

    .legend-dot { width: 8px; height: 8px; border-radius: 999px; display: inline-block; flex-shrink: 0; }

    .attention-row { border-left: 3px solid transparent; }
    .attention-row.is-overdue { border-color: var(--rose); background: #fef5f6; }
    .attention-row.is-urgent { border-color: var(--gold-500); background: #fefaf3; }

    .clean-scroll::-webkit-scrollbar { height: 8px; width: 8px; }
    .clean-scroll::-webkit-scrollbar-track { background: transparent; }
    .clean-scroll::-webkit-scrollbar-thumb { background: #d4d4d8; border-radius: 10px; }
    .clean-scroll::-webkit-scrollbar-thumb:hover { background: #a1a1aa; }

    /* Notification dropdown entrance */
    #notificationDropdown {
        opacity: 0;
        transform: translateY(-8px) scale(0.97);
        pointer-events: none;
        transition: opacity 0.18s ease, transform 0.18s ease;
    }
    #notificationDropdown.show {
        opacity: 1;
        transform: translateY(0) scale(1);
        pointer-events: auto;
    }

    /* Unread notification row highlight */
    .notif-item.is-unread {
        background: #f8faff;
        border-left: 3px solid var(--ink-blue);
    }
    .notif-item.is-unread:hover {
        background: #eef2fd;
    }
</style>
</head>

<body class="text-zinc-900 antialiased">

<div class="flex h-screen overflow-hidden">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="p-6 lg:p-8 space-y-6">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-display font-bold tracking-tight text-zinc-900"><?= $greeting ?>, <?= htmlspecialchars($userFirstName) ?> 👋</h1>
                    <p class="text-xs text-zinc-400 mt-1">Here's where your task pipeline stands right now.</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="text-xs text-zinc-400 font-mono-num bg-white px-2.5 py-1.5 border border-zinc-200 rounded-md shadow-sm">
                        <?= date("d M Y, h:i A"); ?>
                    </div>

                    <!-- ===== UNIVERSAL NOTIFICATION BELL + DROPDOWN ===== -->
                    <?php include __DIR__ . "/../partials/notification_bell.php"; ?>
                    <!-- ===== END NOTIFICATION BELL ===== -->

                    <a href="create_task.php" class="flex items-center gap-1.5 bg-zinc-900 hover:bg-zinc-800 text-white text-xs font-semibold px-3.5 py-1.5 rounded-md shadow-sm transition-colors">
                        <span class="material-icons-round text-sm">add</span> New Task
                    </a>
                </div>
            </div>

            <!-- KPI Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Total Tasks</div>
                        <span class="kpi-icon" style="background:#f4f4f5;"><span class="material-icons-round text-[16px] text-zinc-500">inventory_2</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold text-zinc-900 mt-2"><?= (int)$stats['total'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">New</div>
                        <span class="kpi-icon" style="background:#f4f4f5;"><span class="material-icons-round text-[16px] text-zinc-500">fiber_new</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold text-zinc-700 mt-2"><?= (int)$stats['new_count'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Working</div>
                        <span class="kpi-icon" style="background:var(--ink-blue-100);"><span class="material-icons-round text-[16px]" style="color:var(--ink-blue);">bolt</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold mt-2" style="color:var(--ink-blue);"><?= (int)$stats['in_progress'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Rework</div>
                        <span class="kpi-icon" style="background:var(--rose-100);"><span class="material-icons-round text-[16px]" style="color:var(--rose);">autorenew</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold mt-2" style="color:var(--rose);"><?= (int)$stats['rework'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Urgent</div>
                        <span class="kpi-icon" style="background:var(--gold-100);"><span class="material-icons-round text-[16px]" style="color:var(--gold-600);">priority_high</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold mt-2" style="color:var(--gold-600);"><?= (int)$stats['urgent'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Overdue</div>
                        <span class="kpi-icon" style="background:var(--rose-100);"><span class="material-icons-round text-[16px]" style="color:var(--rose);">schedule</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold mt-2" style="color:var(--rose);"><?= (int)$stats['overdue'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="flex items-center justify-between">
                        <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Completed</div>
                        <span class="kpi-icon" style="background:var(--emerald-100);"><span class="material-icons-round text-[16px]" style="color:var(--emerald);">task_alt</span></span>
                    </div>
                    <div class="text-2xl font-display font-bold mt-2" style="color:var(--emerald);"><?= (int)$stats['completed'] ?></div>
                </div>
                <div class="kpi-card flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between">
                            <div class="text-[9px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Total Words</div>
                            <span class="kpi-icon" style="background:#f4f4f5;"><span class="material-icons-round text-[16px] text-zinc-500">edit_note</span></span>
                        </div>
                        <div class="text-2xl font-display font-bold text-zinc-900 mt-2"><?= number_format($stats['total_words']) ?></div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-zinc-100 flex items-center justify-between text-[10px]">
                        <span class="text-zinc-400 font-medium"><?= date('M') ?> Month:</span>
                        <span class="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full"><?= number_format($stats['monthly_words']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Ink Flow Pipeline Rail -->
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div class="text-[10px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Pipeline Flow</div>
                    <div class="text-[10px] text-zinc-400"><?= $pipelineTotal == 1 && array_sum($stageCounts) == 0 ? 0 : array_sum($stageCounts) ?> tasks total</div>
                </div>
                <div class="ink-rail">
                    <?php foreach ($stageOrder as $stageKey): 
                        $count = $stageCounts[$stageKey];
                        if ($count <= 0) continue;
                        $pct = round(($count / $pipelineTotal) * 100, 2);
                    ?>
                        <div class="ink-seg <?= $stageKey === 'in_progress' ? 'is-active' : '' ?>"
                             style="width: <?= $pct ?>%; background: <?= $stageColors[$stageKey] ?>;"
                             title="<?= $stageLabels[$stageKey] ?>: <?= $count ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="flex flex-wrap gap-x-5 gap-y-2 mt-4">
                    <?php foreach ($stageOrder as $stageKey): ?>
                        <div class="flex items-center gap-1.5 text-xs text-zinc-600">
                            <span class="legend-dot" style="background: <?= $stageColors[$stageKey] ?>;"></span>
                            <span class="font-medium"><?= $stageLabels[$stageKey] ?></span>
                            <span class="font-mono-num text-zinc-400">(<?= $stageCounts[$stageKey] ?>)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ===================== 📅 DAILY TASK SECTION (DATE-WISE BREAKDOWN) ===================== -->
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4 pb-3 border-b border-zinc-100">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                            <span class="material-icons-round text-lg">calendar_today</span>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-bold text-zinc-800">Daily Task Log & Archive</h3>
                                <span class="text-xs font-semibold text-zinc-400">• <?= date('d M Y', strtotime($dashDate)) ?></span>
                                <?php if ($dashDate === date('Y-m-d')): ?>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider">Today</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[11px] text-zinc-500">Inspect tasks you booked on this date</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="?dash_date=<?= date('Y-m-d') ?>" 
                           class="px-2.5 py-1 rounded text-xs font-semibold <?= $dashDate === date('Y-m-d') ? 'bg-zinc-900 text-white' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200' ?>">
                            Today
                        </a>
                        <a href="?dash_date=<?= date('Y-m-d', strtotime('-1 day')) ?>" 
                           class="px-2.5 py-1 rounded text-xs font-semibold <?= $dashDate === date('Y-m-d', strtotime('-1 day')) ? 'bg-zinc-900 text-white' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200' ?>">
                            Yesterday
                        </a>

                        <form method="GET" class="flex items-center m-0">
                            <input type="date" name="dash_date" value="<?= htmlspecialchars($dashDate) ?>"
                                   onchange="this.form.submit()"
                                   class="px-2.5 py-1 bg-zinc-50 border border-zinc-200 rounded text-xs font-semibold text-zinc-700 cursor-pointer focus:outline-none focus:ring-1 focus:ring-zinc-500">
                        </form>

                        <a href="today_tasks.php?date=<?= $dashDate ?>" 
                           class="px-3 py-1 rounded text-xs font-bold bg-[#eba940] text-slate-900 hover:bg-amber-400 flex items-center gap-1 transition-colors">
                            Full Day View <span class="material-icons-round text-sm">arrow_forward</span>
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                    <div class="p-2.5 bg-zinc-50 rounded border border-zinc-100">
                        <span class="text-[9px] font-black uppercase tracking-wider text-zinc-400">Total Booked</span>
                        <p class="text-base font-extrabold text-zinc-800"><?= $dailyStats['total'] ?> Tasks</p>
                    </div>
                    <div class="p-2.5 bg-zinc-50 rounded border border-zinc-100">
                        <span class="text-[9px] font-black uppercase tracking-wider text-sky-500">New in Queue</span>
                        <p class="text-base font-extrabold text-zinc-800"><?= $dailyStats['new'] ?> Tasks</p>
                    </div>
                    <div class="p-2.5 bg-zinc-50 rounded border border-zinc-100">
                        <span class="text-[9px] font-black uppercase tracking-wider text-amber-500">In Pipeline</span>
                        <p class="text-base font-extrabold text-zinc-800"><?= $dailyStats['working'] ?> Tasks</p>
                    </div>
                    <div class="p-2.5 bg-zinc-50 rounded border border-zinc-100">
                        <span class="text-[9px] font-black uppercase tracking-wider text-emerald-500">Completed</span>
                        <p class="text-base font-extrabold text-emerald-600"><?= $dailyStats['completed'] ?> Delivered</p>
                    </div>
                </div>

                <?php if (count($dailyTasks) > 0): ?>
                <div class="space-y-1.5 max-h-64 overflow-y-auto pr-1">
                    <?php foreach (array_slice($dailyTasks, 0, 6) as $dt): 
                        $clLink = getDriveClientLink($dt);
                        $opLink = getDriveOperationsLink($dt);
                    ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-2.5 border border-zinc-100 rounded hover:bg-zinc-50/60 transition-colors gap-2">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-7 h-7 rounded bg-zinc-100 text-zinc-700 flex items-center justify-center font-bold text-[11px] flex-shrink-0">
                                <?= substr($dt['task_key'], -2) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-zinc-800 truncate"><?= htmlspecialchars($dt['task_name']) ?></p>
                                <p class="text-[10px] text-zinc-400 font-semibold uppercase tracking-wide">
                                    <?= $dt['task_key'] ?> · <?= number_format($dt['word_count'] ?? 0) ?> words · Client: <?= htmlspecialchars($dt['client_name'] ?? 'N/A') ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 self-end sm:self-center flex-shrink-0">
                            <?php if (!empty($clLink)): ?>
                            <a href="<?= htmlspecialchars($clLink) ?>" target="_blank"
                               class="px-2 py-0.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded text-[10px] font-bold transition-all">
                                Client Files
                            </a>
                            <?php elseif(!empty($dt['file_path']) && file_exists('../../' . $dt['file_path'])): ?>
                            <a href="../../<?= htmlspecialchars($dt['file_path']) ?>" download
                               class="px-2 py-0.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded text-[10px] font-bold transition-all">
                                Client Brief
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($opLink)): ?>
                            <a href="<?= htmlspecialchars($opLink) ?>" target="_blank"
                               class="px-2 py-0.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded text-[10px] font-bold transition-all">
                                Ops Files
                            </a>
                            <?php elseif(!empty($dt['team_file']) && file_exists('../../' . $dt['team_file'])): ?>
                            <a href="../../<?= htmlspecialchars($dt['team_file']) ?>" download
                               class="px-2 py-0.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded text-[10px] font-bold transition-all">
                                Ops Files
                            </a>
                            <?php endif; ?>
                            <span class="px-2 py-0.5 border text-[9px] font-bold rounded uppercase tracking-wider <?= getStageBadgeClass($dt['status']) ?>">
                                <?= str_replace('_', ' ', $dt['status']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($dailyTasks) > 6): ?>
                <div class="text-center pt-2.5 border-t border-zinc-100 mt-2">
                    <a href="today_tasks.php?date=<?= $dashDate ?>" class="text-xs font-bold text-zinc-700 hover:underline">
                        View all <?= count($dailyTasks) ?> tasks for this date →
                    </a>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-5 text-zinc-400">
                    <span class="material-icons-round text-2xl text-zinc-300 mb-1">event_busy</span>
                    <p class="text-xs font-semibold">No tasks created on <?= date('d M Y', strtotime($dashDate)) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activity & Needs Attention -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                <!-- Recent Activity -->
                <div class="bg-white border border-zinc-200 rounded-md shadow-sm overflow-hidden flex flex-col">
                    <div class="p-4 border-b border-zinc-100 flex justify-between items-center bg-zinc-50/50">
                        <div class="text-[10px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Recent Activity</div>
                        <a href="my_tasks.php" class="text-[10px] font-medium text-zinc-500 hover:text-zinc-900 transition-colors">View All →</a>
                    </div>
                    <div class="flex-1 overflow-y-auto clean-scroll max-h-80">
                        <table class="data-table">
                            <tbody>
                                <?php if(count($recent) > 0): ?>
                                    <?php foreach($recent as $r): ?>
                                    <tr>
                                        <td class="w-8">
                                            <?php if($r['urgent'] == 'yes'): ?>
                                                <span class="w-2 h-2 rounded-full inline-block" style="background: var(--rose);"></span>
                                            <?php else: ?>
                                                <span class="w-2 h-2 bg-zinc-200 rounded-full inline-block"></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-mono-num text-[11px] font-semibold text-zinc-800 truncate max-w-[140px]" title="<?= $r['task_key'] ?>"><?= $r['task_key'] ?></div>
                                            <div class="text-[10px] text-zinc-400 truncate max-w-[140px]" title="<?= htmlspecialchars($r['task_name']) ?>"><?= htmlspecialchars($r['task_name']) ?></div>
                                        </td>
                                        <td class="text-right">
                                            <span class="chip chip-<?= $r['status'] ?>">
                                                <?= str_replace('_', ' ', $r['status']) ?>
                                            </span>
                                            <div class="text-[9px] text-zinc-300 mt-1"><?= timeAgo($r['task_created_at']) ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td class="text-center py-10 text-zinc-300 text-[10px] uppercase tracking-widest">
                                            <span class="material-icons-round text-3xl block mb-2 text-zinc-200">inventory_2</span>
                                            No tasks yet — create your first one
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Needs Attention -->
                <div class="bg-white border border-zinc-200 rounded-md shadow-sm overflow-hidden flex flex-col">
                    <div class="p-4 border-b border-zinc-100 flex justify-between items-center bg-zinc-50/50">
                        <div class="text-[10px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Needs Attention</div>
                        <span class="text-[10px] text-zinc-400"><?= count($attention) ?> flagged</span>
                    </div>
                    <div class="flex-1 overflow-y-auto clean-scroll max-h-80 divide-y divide-zinc-100">
                        <?php if(count($attention) > 0): ?>
                            <?php foreach($attention as $a): 
                                $isOverdue = strtotime($a['deadline']) < time();
                            ?>
                            <div class="attention-row <?= $isOverdue ? 'is-overdue' : 'is-urgent' ?> px-4 py-2.5 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-mono-num text-[11px] font-semibold text-zinc-800 truncate"><?= $a['task_key'] ?></div>
                                    <div class="text-[10px] text-zinc-400 truncate max-w-[220px]" title="<?= htmlspecialchars($a['task_name']) ?>"><?= htmlspecialchars($a['task_name']) ?></div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <div class="text-[10px] font-semibold <?= $isOverdue ? 'text-rose-600' : 'text-amber-600' ?>">
                                        <?= $isOverdue ? 'Overdue' : 'Urgent' ?>
                                    </div>
                                    <div class="text-[9px] text-zinc-400 font-mono-num"><?= date("d M, H:i", strtotime($a['deadline'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-10 text-zinc-300 text-[10px] uppercase tracking-widest">
                                <span class="material-icons-round text-3xl block mb-2 text-emerald-200">task_alt</span>
                                All caught up — nothing urgent or overdue
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Detailed Breakdown Table -->
            <div class="bg-white border border-zinc-200 rounded-md shadow-sm overflow-hidden">
                <div class="p-4 border-b border-zinc-100 bg-zinc-50/50">
                    <div class="text-[10px] font-display font-semibold text-zinc-400 uppercase tracking-widest">Detailed Pipeline Breakdown</div>
                </div>
                <div class="overflow-x-auto clean-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Task Key</th>
                                <th>Task / Client</th>
                                <th class="text-center">Words</th>
                                <th>Deadline</th>
                                <th class="text-center">Priority</th>
                                <th class="text-center">Stage</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $detailQuery = "SELECT task_key, task_name, client_name, current_stage, word_count, deadline, urgent, status 
                                              FROM tasks WHERE created_by = :user 
                                              ORDER BY id DESC LIMIT 10";
                            $stmtDetail = $db->prepare($detailQuery);
                            $stmtDetail->execute([':user' => $user]);
                            $details = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);
                            
                            if(count($details) > 0):
                                foreach($details as $d): 
                                    $isOverdue = strtotime($d['deadline']) < time();
                            ?>
                            <tr class="<?= $isOverdue && $d['status'] != 'completed' ? 'bg-rose-50/30' : '' ?>">
                                <td class="font-mono-num text-xs font-semibold text-zinc-800"><?= $d['task_key'] ?></td>
                                <td>
                                    <div class="text-xs font-medium text-zinc-700 truncate max-w-[180px]" title="<?= htmlspecialchars($d['task_name']) ?>"><?= htmlspecialchars($d['task_name']) ?></div>
                                    <div class="text-[10px] text-zinc-400 flex items-center gap-1 mt-0.5">
                                        <span class="material-icons-round" style="font-size: 10px;">person</span>
                                        <?= htmlspecialchars($d['client_name'] ?: 'N/A') ?>
                                    </div>
                                </td>
                                <td class="text-center text-xs text-zinc-500 font-mono-num"><?= number_format($d['word_count']) ?></td>
                                <td class="text-xs <?= $isOverdue && $d['status'] != 'completed' ? 'text-rose-600 font-semibold' : 'text-zinc-500' ?>">
                                    <?= date("d M, H:i", strtotime($d['deadline'])) ?>
                                </td>
                                <td class="text-center">
                                    <?php if($d['urgent'] == 'yes'): ?>
                                        <span class="bg-rose-50 text-rose-600 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase">Urgent</span>
                                    <?php else: ?>
                                        <span class="text-zinc-300 text-[9px] font-bold uppercase">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-[10px] text-zinc-400 font-medium uppercase tracking-wide"><?= str_replace('_', ' ', $d['current_stage']) ?></td>
                                <td class="text-center">
                                    <span class="chip chip-<?= $d['status'] ?>">
                                        <?= str_replace('_', ' ', $d['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-10 text-zinc-300 text-xs uppercase tracking-widest">No tasks recorded yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- ===== DROPDOWN TOGGLE JS ===== -->
<script>
const notifBtn = document.getElementById("notificationBtn");
const notifBox = document.getElementById("notificationDropdown");

notifBtn.addEventListener("click", function(e) {
    e.stopPropagation();
    notifBox.classList.toggle("show");
});

document.addEventListener("click", function(e) {
    if (!notifBox.contains(e.target)) {
        notifBox.classList.remove("show");
    }
});

/* Prevent dropdown close when clicking inside it (for "Mark all as read" link) */
notifBox.addEventListener("click", function(e) {
    e.stopPropagation();
});
</script>

<!-- ===== LOGIN TOAST (only if unread notifications exist) ===== -->
<?php if($unreadCount > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        toast: true,
        position: "top-end",
        timer: 5000,
        timerProgressBar: true,
        showConfirmButton: false,
        icon: "info",
        title: "You have <?= $unreadCount ?> new notification<?= $unreadCount > 1 ? 's' : '' ?>."
    });
});
</script>
<?php endif; ?>

</body>
</html>