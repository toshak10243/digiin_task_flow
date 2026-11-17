<?php
require_once "../../app/middleware/AdminMiddleware.php";
require_once "../../app/config/database.php";

date_default_timezone_set('Asia/Kolkata');
$db = (new Database())->connect();

/* DATE FILTER */
$reportDate = $_GET['report_date'] ?? date('Y-m-d');
$start = $reportDate . " 00:00:00";
$end   = $reportDate . " 23:59:59";

/* ===== TASK STATS ===== */
$stmt = $db->prepare("
SELECT COUNT(*) total,
SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) progress,
SUM(CASE WHEN status='rework' THEN 1 ELSE 0 END) rework,
SUM(CASE WHEN urgent='yes' THEN 1 ELSE 0 END) urgent,
SUM(word_count) words
FROM tasks WHERE task_created_at BETWEEN :s AND :e
");
$stmt->execute([':s'=>$start, ':e'=>$end]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

/* GRAPH DATA */
$statusData = [
    (int)$stats['completed'],
    (int)$stats['progress'],
    (int)$stats['rework'],
    (int)$stats['urgent']
];

/* ===== BDE PERFORMANCE ===== */
$bde = $db->query("
SELECT u.username,
COUNT(t.id) total,
SUM(t.word_count) words,
SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) completed,
SUM(CASE WHEN t.status='rework' THEN 1 ELSE 0 END) rework
FROM users u
LEFT JOIN tasks t ON u.id=t.created_by
WHERE u.role='bde'
GROUP BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

/* ===== TEAM PERFORMANCE ===== */
$team = $db->query("
SELECT u.username,
COUNT(t.id) total,
SUM(t.word_count) words,
SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) completed,
SUM(CASE WHEN t.status='rework' THEN 1 ELSE 0 END) rework
FROM users u
LEFT JOIN tasks t ON u.id=t.assigned_to
WHERE u.role='operations_team'
GROUP BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

/* ===== QC STATS ===== */
$qc = $db->query("
SELECT COUNT(*) reviewed,
SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) approved,
SUM(CASE WHEN status='rework' THEN 1 ELSE 0 END) reworks
FROM tasks
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Analytics | Digiink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .stat-card { transition: all 0.3s ease; border: 1px solid #eef2f6; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05); }
        .report-table th { background-color: #f1f5f9; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; font-weight: 800; padding: 15px; border: none; }
        .report-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-weight: 500; text-align: center; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>

<body class="text-slate-900 custom-scrollbar">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 space-y-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div>
                <h1 class="text-3xl font-black text-slate-800 tracking-tight flex items-center gap-3">
                    <span class="p-3 bg-indigo-600 rounded-2xl shadow-xl shadow-indigo-100">
                        <span class="material-icons-round text-white">analytics</span>
                    </span>
                    System Analytics
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Detailed performance report for <span class="text-indigo-600 font-bold"><?= date("d M Y", strtotime($reportDate)) ?></span></p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <form class="flex gap-2 bg-white p-2 rounded-2xl shadow-sm border border-slate-200">
                    <input type="date" name="report_date" value="<?= $reportDate ?>"
                           class="bg-transparent px-3 py-1 text-sm font-bold text-slate-600 outline-none">
                    <button class="bg-indigo-600 hover:bg-slate-900 text-white px-5 py-2 rounded-xl text-xs font-black uppercase tracking-widest transition-all">
                        Filter
                    </button>
                </form>

                <a href="download_report.php?report_date=<?= $reportDate ?>"
                   class="bg-rose-500 hover:bg-rose-600 text-white px-5 py-3 rounded-2xl text-xs font-black uppercase tracking-widest shadow-lg shadow-rose-100 flex items-center gap-2 transition-all">
                    <span class="material-icons-round text-sm">picture_as_pdf</span> PDF Report
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-6 gap-6">
            <?php
            $cards = [
                ['Total Tasks', $stats['total'], 'blue'],
                ['Completed', $stats['completed'], 'emerald'],
                ['In Progress', $stats['progress'], 'amber'],
                ['Rework', $stats['rework'], 'rose'],
                ['Urgent', $stats['urgent'], 'orange'],
                ['Total Words', number_format($stats['words']), 'purple']
            ];
            foreach($cards as $c): ?>
            <div class="stat-card bg-white p-6 rounded-[2rem] border-l-4 border-l-<?= $c[2] ?>-500">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-3"><?= $c[0] ?></p>
                <p class="text-2xl font-black text-<?= $c[2] ?>-600 tracking-tighter"><?= $c[1] ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                <h2 class="text-lg font-black text-slate-800 mb-6 flex items-center gap-2 uppercase tracking-tight">
                    <span class="w-1.5 h-6 bg-indigo-600 rounded-full"></span> Status Overview
                </h2>
                <div id="statusChart"></div>
            </div>

            <div class="bg-slate-900 p-8 rounded-[2.5rem] shadow-2xl flex flex-col justify-between relative overflow-hidden">
                <span class="material-icons-round absolute -right-4 -bottom-4 text-white/5 text-9xl">fact_check</span>
                <div>
                    <h2 class="text-xl font-black text-white italic uppercase tracking-tighter mb-1">QC Quality</h2>
                    <p class="text-slate-500 text-xs font-bold uppercase tracking-widest">Lifetime Audit Stats</p>
                </div>
                
                <div class="space-y-6 relative z-10">
                    <div class="flex justify-between items-end border-b border-slate-800 pb-3">
                        <span class="text-slate-400 text-xs font-bold">Total Reviewed</span>
                        <span class="text-2xl font-black text-blue-400"><?= $qc['reviewed'] ?></span>
                    </div>
                    <div class="flex justify-between items-end border-b border-slate-800 pb-3">
                        <span class="text-slate-400 text-xs font-bold text-emerald-400">Approved</span>
                        <span class="text-2xl font-black text-emerald-400"><?= $qc['approved'] ?></span>
                    </div>
                    <div class="flex justify-between items-end">
                        <span class="text-slate-400 text-xs font-bold text-rose-400">Reworks</span>
                        <span class="text-2xl font-black text-rose-400"><?= $qc['reworks'] ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
            
            <div class="space-y-4">
                <h3 class="text-lg font-black text-slate-800 flex items-center gap-2 uppercase tracking-tight ml-2">
                    <span class="material-icons-round text-blue-600">stars</span> BDE Performance
                </h3>
                <div class="bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full report-table">
                        <thead>
                            <tr>
                                <th class="!text-left pl-8">BDE Name</th>
                                <th>Total</th>
                                <th>Done</th>
                                <th>Rework</th>
                                <th class="pr-8">Words</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($bde as $r): ?>
                            <tr class="hover:bg-slate-50/80 transition-all">
                                <td class="!text-left pl-8 font-black text-indigo-600 italic uppercase tracking-tighter"><?= $r['username'] ?></td>
                                <td><?= $r['total'] ?></td>
                                <td class="text-emerald-600 font-black"><?= $r['completed'] ?></td>
                                <td class="text-rose-500 font-black"><?= $r['rework'] ?></td>
                                <td class="pr-8 font-black text-slate-700"><?= number_format($r['words'] ?: 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-4">
                <h3 class="text-lg font-black text-slate-800 flex items-center gap-2 uppercase tracking-tight ml-2">
                    <span class="material-icons-round text-purple-600">psychology</span> Team Performance
                </h3>
                <div class="bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full report-table">
                        <thead>
                            <tr>
                                <th class="!text-left pl-8">Expert</th>
                                <th>Total</th>
                                <th>Done</th>
                                <th>Rework</th>
                                <th class="pr-8">Words</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($team as $r): ?>
                            <tr class="hover:bg-slate-50/80 transition-all">
                                <td class="!text-left pl-8 font-black text-purple-600 italic uppercase tracking-tighter"><?= $r['username'] ?></td>
                                <td><?= $r['total'] ?></td>
                                <td class="text-emerald-600 font-black"><?= $r['completed'] ?></td>
                                <td class="text-rose-500 font-black"><?= $r['rework'] ?></td>
                                <td class="pr-8 font-black text-slate-700"><?= number_format($r['words'] ?: 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>
</div>

<script>
new ApexCharts(document.querySelector("#statusChart"), {
    chart: { type: 'bar', height: 350, fontFamily: 'Plus Jakarta Sans', toolbar: {show: false} },
    series: [{ name: 'Tasks', data: <?= json_encode($statusData) ?> }],
    xaxis: { categories: ['Completed', 'In Progress', 'Rework', 'Urgent'], axisBorder: {show: false}, axisTicks: {show: false} },
    colors: ['#10b981', '#f59e0b', '#ef4444', '#f97316'],
    plotOptions: { bar: { borderRadius: 12, columnWidth: '45%', distributed: true } },
    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
    dataLabels: { enabled: false }
}).render();
</script>

</body>
</html>