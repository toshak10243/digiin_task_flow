<?php 
require_once "../../app/middleware/AdminMiddleware.php";
require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

date_default_timezone_set('Asia/Kolkata');

/* 📅 Daily Task Section (Date-Wise) */
$dashDate = $_GET['dash_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
    $dashDate = date('Y-m-d');
}

$stmtDaily = $db->prepare("
    SELECT t.*, u.name AS team_name, bde.name AS bde_name
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
    'working'   => 0,
    'rework'    => 0,
    'words'     => 0
];
foreach ($dailyTasks as $dt) {
    $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
    if ($dt['status'] === 'completed' || $dt['current_stage'] === 'completed') $dailyStats['completed']++;
    elseif ($dt['status'] === 'rework') $dailyStats['rework']++;
    else $dailyStats['working']++;
}

/* ================== SYSTEM STATS ================== */
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalTasks = $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$inProgress = $db->query("SELECT COUNT(*) FROM tasks WHERE status='in_progress'")->fetchColumn();
$completed  = $db->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn();
$urgent     = $db->query("SELECT COUNT(*) FROM tasks WHERE urgent='yes'")->fetchColumn();
$totalWords = $db->query("SELECT COALESCE(SUM(word_count),0) FROM tasks")->fetchColumn();
$currentMonth = date('Y-m');
$stmtMonthWords = $db->prepare("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = :currMonth");
$stmtMonthWords->execute([':currMonth' => $currentMonth]);
$monthlyWords = (int)$stmtMonthWords->fetchColumn();

/* ================== TOP BDE ================== */
$topBDE = $db->query("
SELECT u.username, COUNT(t.id) total_tasks, SUM(t.word_count) words
FROM users u
JOIN tasks t ON u.id=t.created_by
WHERE u.role='bde'
GROUP BY u.id
ORDER BY total_tasks DESC
LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ================== TOP TEAM ================== */
$topTeam = $db->query("
SELECT u.username, COUNT(t.id) completed_tasks, SUM(t.word_count) words
FROM users u
JOIN tasks t ON u.id=t.assigned_to
WHERE u.role='operations_team' AND t.status='completed'
GROUP BY u.id
ORDER BY completed_tasks DESC
LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ================== RECENT ================== */
$recent = $db->query("
SELECT task_key, task_name, status, current_stage, deadline
FROM tasks ORDER BY id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    .stat-card { background: #ffffff; border-radius: 20px; transition: all 0.3s ease; border: 1px solid #eef2f6; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.05); }
    .table-container { background: #ffffff; border-radius: 20px; border: 1px solid #eef2f6; overflow: hidden; }
</style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">

        <div class="flex justify-between items-center mb-10">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-800 tracking-tight">
                    Welcome, <span class="text-blue-600"><?= $_SESSION['username'] ?></span>
                </h1>
                <p class="text-slate-400 font-medium text-sm mt-1 uppercase tracking-widest">Admin Dashboard Overview</p>
            </div>
            <div class="flex items-center gap-3">
                <!-- 🔔 UNIVERSAL NOTIFICATION BELL + DROPDOWN -->
                <?php include __DIR__ . "/../partials/notification_bell.php"; ?>

                <div class="bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200 text-sm font-bold text-slate-600">
                    <span class="material-icons-round text-base align-middle mr-1 text-blue-500">schedule</span>
                    <?= date("d M Y, h:i A") ?>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-12">
            
            <div class="stat-card p-6 border-t-4 border-blue-600 text-center">
                <p class="text-slate-400 text-xs font-black uppercase tracking-widest">Total Users</p>
                <p class="text-3xl font-black text-blue-700 mt-2"><?= $totalUsers ?></p>
            </div>

            <div class="stat-card p-6 border-t-4 border-green-600 text-center">
                <p class="text-slate-400 text-xs font-black uppercase tracking-widest">Total Tasks</p>
                <p class="text-3xl font-black text-green-700 mt-2"><?= $totalTasks ?></p>
            </div>

            <div class="stat-card p-6 border-t-4 border-yellow-500 text-center">
                <p class="text-slate-400 text-xs font-black uppercase tracking-widest">In Progress</p>
                <p class="text-3xl font-black text-yellow-600 mt-2"><?= $inProgress ?></p>
            </div>

            <div class="stat-card p-6 border-t-4 border-purple-600 text-center">
                <p class="text-slate-400 text-xs font-black uppercase tracking-widest">Completed</p>
                <p class="text-3xl font-black text-purple-700 mt-2"><?= $completed ?></p>
            </div>

            <div class="stat-card p-6 border-t-4 border-red-600 text-center">
                <p class="text-slate-400 text-xs font-black uppercase tracking-widest">Urgent</p>
                <p class="text-3xl font-black text-red-600 mt-2"><?= $urgent ?></p>
            </div>

            <div class="stat-card p-6 border-t-4 border-indigo-600 text-center flex flex-col justify-between">
                <div>
                    <p class="text-slate-400 text-xs font-black uppercase tracking-widest">Total Words</p>
                    <p class="text-2xl font-black text-indigo-700 mt-1"><?= number_format($totalWords) ?></p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px]">
                    <span class="text-slate-400 font-bold"><?= date('M') ?> Month:</span>
                    <span class="font-extrabold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full"><?= number_format($monthlyWords) ?></span>
                </div>
            </div>

        </div>

        <!-- ===================== 📅 DAILY TASK SECTION (DATE-WISE BREAKDOWN) ===================== -->
        <div class="bg-white rounded-3xl p-6 lg:p-8 border border-slate-100 shadow-sm mb-12">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 pb-4 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-slate-900 text-white flex items-center justify-center font-bold">
                        <span class="material-icons-round text-xl">calendar_today</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-800">System Daily Task Archive</h3>
                            <span class="text-xs font-bold text-slate-400">• <?= date('d M Y', strtotime($dashDate)) ?></span>
                            <?php if ($dashDate === date('Y-m-d')): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider">Today</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500">Track and review all tasks booked across the system by date</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <a href="?dash_date=<?= date('Y-m-d') ?>" 
                       class="px-3 py-1.5 rounded-xl text-xs font-bold <?= $dashDate === date('Y-m-d') ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        Today
                    </a>
                    <a href="?dash_date=<?= date('Y-m-d', strtotime('-1 day')) ?>" 
                       class="px-3 py-1.5 rounded-xl text-xs font-bold <?= $dashDate === date('Y-m-d', strtotime('-1 day')) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        Yesterday
                    </a>

                    <form method="GET" class="flex items-center m-0">
                        <input type="date" name="dash_date" value="<?= htmlspecialchars($dashDate) ?>"
                               onchange="this.form.submit()"
                               class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 cursor-pointer focus:outline-none focus:ring-2 focus:ring-slate-900">
                    </form>

                    <a href="today_tasks.php?date=<?= $dashDate ?>" 
                       class="px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-600 text-white hover:bg-indigo-700 flex items-center gap-1 transition-colors">
                        Full Daily Hub <span class="material-icons-round text-sm">arrow_forward</span>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Total Booked</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= $dailyStats['total'] ?> Tasks</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-amber-500">Active / Pipeline</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= $dailyStats['working'] ?> Tasks</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-emerald-500">Completed</span>
                    <p class="text-lg font-extrabold text-emerald-600"><?= $dailyStats['completed'] ?> Delivered</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-indigo-500">Word Count</span>
                    <p class="text-lg font-extrabold text-indigo-600"><?= number_format($dailyStats['words']) ?> Words</p>
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
                        <div class="w-8 h-8 rounded-lg bg-slate-100 text-slate-800 flex items-center justify-center font-bold text-xs flex-shrink-0">
                            <?= substr($dt['task_key'], -2) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($dt['task_name']) ?></p>
                            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wide">
                                <?= $dt['task_key'] ?> · <?= number_format($dt['word_count'] ?? 0) ?> words · Expert: <?= htmlspecialchars($dt['team_name'] ?? 'Queue') ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 self-end sm:self-center flex-shrink-0">
                        <?php if (!empty($clLink)): ?>
                        <a href="<?= htmlspecialchars($clLink) ?>" target="_blank"
                           class="px-2 py-1 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded text-[10px] font-bold transition-all">
                            Client Files
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($opLink)): ?>
                        <a href="<?= htmlspecialchars($opLink) ?>" target="_blank"
                           class="px-2 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded text-[10px] font-bold transition-all">
                            Ops Files
                        </a>
                        <?php endif; ?>
                        <span class="px-2.5 py-0.5 border text-[9px] font-bold rounded-lg uppercase tracking-wider bg-slate-100 text-slate-600 border-slate-200">
                            <?= str_replace('_', ' ', $dt['status']) ?>
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
                <span class="material-icons-round text-3xl text-slate-300 mb-1">event_busy</span>
                <p class="text-xs font-semibold">No tasks recorded on <?= date('d M Y', strtotime($dashDate)) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
            <div class="table-container p-6 shadow-sm">
                <div id="taskChart"></div>
            </div>
            <div class="table-container p-6 shadow-sm">
                <div id="statusChart"></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">

            <div class="table-container shadow-sm">
                <div class="p-6 border-b border-slate-50 flex items-center gap-2">
                    <span class="material-icons-round text-blue-600">leaderboard</span>
                    <h2 class="text-lg font-black text-slate-800">Top Performing BDE</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-center">
                        <tr class="bg-blue-50/50 text-[11px] font-black text-blue-600 uppercase tracking-widest">
                            <th class="p-4">Name</th>
                            <th class="p-4">Tasks</th>
                            <th class="p-4">Words</th>
                        </tr>
                        <?php foreach($topBDE as $b): ?>
                        <tr class="border-t border-slate-50 hover:bg-slate-50/50 transition-colors">
                            <td class="p-4 font-bold text-slate-700"><?= $b['username'] ?></td>
                            <td class="p-4 font-black text-blue-700"><?= $b['total_tasks'] ?></td>
                            <td class="p-4 font-black text-indigo-600"><?= number_format($b['words']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div class="table-container shadow-sm">
                <div class="p-6 border-b border-slate-50 flex items-center gap-2">
                    <span class="material-icons-round text-green-600">military_tech</span>
                    <h2 class="text-lg font-black text-slate-800">Top Operations Team</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-center">
                        <tr class="bg-green-50/50 text-[11px] font-black text-green-600 uppercase tracking-widest">
                            <th class="p-4">Name</th>
                            <th class="p-4">Completed</th>
                            <th class="p-4">Words</th>
                        </tr>
                        <?php foreach($topTeam as $t): ?>
                        <tr class="border-t border-slate-50 hover:bg-slate-50/50 transition-colors">
                            <td class="p-4 font-bold text-slate-700"><?= $t['username'] ?></td>
                            <td class="p-4 font-black text-green-700"><?= $t['completed_tasks'] ?></td>
                            <td class="p-4 font-black text-purple-600"><?= number_format($t['words']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

        </div>

        <div class="table-container shadow-sm mb-10">
            <div class="p-6 border-b border-slate-50 flex items-center gap-2">
                <span class="material-icons-round text-slate-400">history</span>
                <h2 class="text-lg font-black text-slate-800">Recent System Activity</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-center">
                    <tr class="bg-slate-50 text-[11px] font-black text-slate-500 uppercase tracking-widest">
                        <th class="p-4">Task</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Stage</th>
                        <th class="p-4">Deadline</th>
                    </tr>
                    <?php foreach($recent as $r): ?>
                    <tr class="border-t border-slate-50 hover:bg-slate-50/50 transition-colors">
                        <td class="p-4 font-bold text-slate-700 italic uppercase"><?= $r['task_key'] ?></td>
                        <td class="p-4 font-black text-blue-600 text-xs">
                            <span class="px-3 py-1 bg-blue-50 rounded-lg"><?= ucfirst($r['status']) ?></span>
                        </td>
                        <td class="p-4 font-black text-purple-600 text-xs uppercase tracking-tighter">
                            <?= ucfirst(str_replace('_',' ',$r['current_stage'])) ?>
                        </td>
                        <td class="p-4 font-bold text-red-500 text-sm">
                            <?= date("d M Y", strtotime($r['deadline'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
new ApexCharts(document.querySelector("#taskChart"), {
    chart: { type: 'bar', height: 320, fontFamily: 'Plus Jakarta Sans', toolbar: {show: false} },
    series: [{ name: 'Tasks', data: [<?= $totalTasks ?>, <?= $completed ?>, <?= $inProgress ?>] }],
    xaxis: { categories: ['Total', 'Completed', 'In Progress'], axisBorder: {show: false} },
    colors: ['#2563eb'],
    plotOptions: { bar: { borderRadius: 8, columnWidth: '40%' } },
    grid: { borderColor: '#f1f5f9' }
}).render();

new ApexCharts(document.querySelector("#statusChart"), {
    chart: { type: 'donut', height: 320, fontFamily: 'Plus Jakarta Sans' },
    series: [<?= $completed ?>, <?= $inProgress ?>, <?= $urgent ?>],
    labels: ['Completed', 'In Progress', 'Urgent'],
    colors: ['#16a34a', '#facc15', '#dc2626'],
    stroke: { show: false },
    legend: { position: 'bottom', fontWeight: 700 }
}).render();
</script>

</body>
</html>