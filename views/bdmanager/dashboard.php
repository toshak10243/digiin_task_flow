<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bdmanager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

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

$total       = $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$completed   = $db->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn();
$pending     = $db->query("SELECT COUNT(*) FROM tasks WHERE status!='completed'")->fetchColumn();
$urgent      = $db->query("SELECT COUNT(*) FROM tasks WHERE urgent='yes'")->fetchColumn();
$rework      = $db->query("SELECT COUNT(*) FROM tasks WHERE status='rework'")->fetchColumn();
$totalWords  = $db->query("SELECT COALESCE(SUM(word_count),0) FROM tasks")->fetchColumn();
$currentMonth = date('Y-m');
$stmtMonthWords = $db->prepare("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = :currMonth");
$stmtMonthWords->execute([':currMonth' => $currentMonth]);
$monthlyWords = (int)$stmtMonthWords->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BDM Console | Digiink Task Flow</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    .stat-card { 
        background: #ffffff; 
        border: 1px solid #eef2f6; 
        border-radius: 24px; 
        transition: all 0.3s ease;
    }
    .stat-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05); 
    }
    .chart-container {
        background: #ffffff;
        border-radius: 30px;
        border: 1px solid #eef2f6;
        padding: 24px;
    }
</style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-800">
                    Welcome, <span class="text-indigo-600"><?= $_SESSION['username'] ?></span>
                </h1>
                <p class="text-slate-500 font-medium mt-1 uppercase text-xs tracking-widest">Business Development Management Dashboard</p>
            </div>
            <div class="flex items-center gap-3">
                <!-- 🔔 UNIVERSAL NOTIFICATION BELL + DROPDOWN -->
                <?php include __DIR__ . "/../partials/notification_bell.php"; ?>

                <div class="bg-white px-5 py-2.5 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-3">
                    <span class="material-icons-round text-indigo-500">calendar_today</span>
                    <span class="text-sm font-bold text-slate-700"><?= date("d M Y, h:i A") ?></span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-10">

            <div class="stat-card p-6 border-t-4 border-blue-500">
                <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest">Total Tasks</p>
                <h2 class="text-3xl font-black text-slate-800 mt-2"><?= $total ?></h2>
            </div>

            <div class="stat-card p-6 border-t-4 border-green-500">
                <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest">Completed</p>
                <h2 class="text-3xl font-black text-green-600 mt-2"><?= $completed ?></h2>
            </div>

            <div class="stat-card p-6 border-t-4 border-yellow-500">
                <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest">Pending</p>
                <h2 class="text-3xl font-black text-yellow-600 mt-2"><?= $pending ?></h2>
            </div>

            <div class="stat-card p-6 border-t-4 border-red-500">
                <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest">Urgent Tasks</p>
                <h2 class="text-3xl font-black text-red-600 mt-2"><?= $urgent ?></h2>
            </div>

            <div class="stat-card p-6 border-t-4 border-purple-500">
                <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest">Rework</p>
                <h2 class="text-3xl font-black text-purple-600 mt-2"><?= $rework ?></h2>
            </div>

            <div class="stat-card p-6 border-t-4 border-indigo-600 flex flex-col justify-between">
                <div>
                    <p class="text-slate-400 text-[11px] font-black uppercase tracking-widest">Total Words</p>
                    <h2 class="text-2xl font-black text-indigo-700 mt-2"><?= number_format($totalWords) ?></h2>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px]">
                    <span class="text-slate-400 font-bold"><?= date('M') ?> Month:</span>
                    <span class="font-extrabold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full"><?= number_format($monthlyWords) ?></span>
                </div>
            </div>

        </div>

        <!-- ===================== 📅 DAILY TASK SECTION (DATE-WISE BREAKDOWN) ===================== -->
        <div class="bg-white rounded-3xl p-6 lg:p-8 border border-slate-100 shadow-sm mb-10">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 pb-4 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                        <span class="material-icons-round text-xl">calendar_today</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-800">Daily Task Log & Archive</h3>
                            <span class="text-xs font-bold text-slate-400">• <?= date('d M Y', strtotime($dashDate)) ?></span>
                            <?php if ($dashDate === date('Y-m-d')): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider">Today</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500">Track and review all tasks booked across dates</p>
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
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

            <div class="chart-container shadow-sm">
                <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2 uppercase text-xs tracking-widest">
                    <span class="w-2 h-2 bg-indigo-600 rounded-full"></span> Task Status Distribution
                </h3>
                <div id="statusChart"></div>
            </div>

            <div class="chart-container shadow-sm">
                <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2 uppercase text-xs tracking-widest">
                    <span class="w-2 h-2 bg-rose-600 rounded-full"></span> Urgency Overview
                </h3>
                <div id="urgentChart"></div>
            </div>

        </div>

    </main>
</div>

<script>
// Status Distribution Donut Chart
var statusChart = new ApexCharts(document.querySelector("#statusChart"), {
    chart: { type: 'donut', height: 320, fontFamily: 'Plus Jakarta Sans' },
    labels: ['Completed', 'Pending', 'Rework'],
    series: [<?= $completed ?>, <?= $pending ?>, <?= $rework ?>],
    colors: ['#22c55e', '#f59e0b', '#ef4444'],
    stroke: { show: false },
    legend: { position: 'bottom', fontWeight: 700 },
    plotOptions: {
        pie: {
            donut: {
                size: '75%',
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Tasks',
                        fontSize: '14px',
                        fontWeight: 800
                    }
                }
            }
        }
    }
});
statusChart.render();

// Urgency Bar Chart
var urgentChart = new ApexCharts(document.querySelector("#urgentChart"), {
    chart: { type: 'bar', height: 320, fontFamily: 'Plus Jakarta Sans', toolbar: {show: false} },
    series: [{ name: "Tasks", data: [<?= $urgent ?>, <?= $total-$urgent ?>] }],
    xaxis: { 
        categories: ['Urgent', 'Normal'],
        axisBorder: {show: false},
        axisTicks: {show: false}
    },
    colors: ['#ef4444', '#6366f1'],
    plotOptions: {
        bar: {
            borderRadius: 12,
            columnWidth: '40%',
            distributed: true
        }
    },
    grid: { borderColor: '#f1f5f9' },
    dataLabels: { enabled: false }
});
urgentChart.render();
</script>

</body>
</html>