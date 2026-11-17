<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole(['qc','operations_manager']);

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

/* ================= STATS ================= */
$pending   = $db->query("SELECT COUNT(*) FROM tasks WHERE current_stage='qc'")->fetchColumn();
$approved  = $db->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn();
$rework    = $db->query("SELECT COUNT(*) FROM tasks WHERE status='rework'")->fetchColumn();
$urgent    = $db->query("SELECT COUNT(*) FROM tasks WHERE current_stage='qc' AND urgent='yes'")->fetchColumn();
$totalWords= $db->query("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE status='completed'")->fetchColumn();
$currentMonth = date('Y-m');
$stmtMonthWords = $db->prepare("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE status='completed' AND DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = :currMonth");
$stmtMonthWords->execute([':currMonth' => $currentMonth]);
$monthlyWords = (int)$stmtMonthWords->fetchColumn();

/* QC STATUS DATA */
$statusData = [
    'Approved' => $approved,
    'Rework'   => $rework,
    'Pending'  => $pending
];

/* 📅 Daily Task Section (Date-Wise) */
$dashDate = $_GET['dash_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
    $dashDate = date('Y-m-d');
}

$stmtDaily = $db->prepare("
    SELECT t.*, expert.name AS expert_name, bde.name AS bde_name
    FROM tasks t
    LEFT JOIN users expert ON t.assigned_to = expert.id
    LEFT JOIN users bde ON t.created_by = bde.id
    WHERE (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)
    ORDER BY t.id DESC
");
$stmtDaily->execute([':dt' => $dashDate]);
$dailyTasks = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

$dailyStats = [
    'total'     => count($dailyTasks),
    'approved'  => 0,
    'qc'        => 0,
    'rework'    => 0,
    'words'     => 0
];
foreach ($dailyTasks as $dt) {
    $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
    if ($dt['status'] === 'completed' || $dt['current_stage'] === 'completed') $dailyStats['approved']++;
    elseif ($dt['current_stage'] === 'qc') $dailyStats['qc']++;
    elseif ($dt['status'] === 'rework') $dailyStats['rework']++;
}

/* RECENT QC ACTIONS */
$recent = $db->query("
    SELECT task_key,
           word_count,
           drive_folder_link,
           qc_notes,
           status,
           current_stage
    FROM tasks
    WHERE current_stage='completed' OR status='rework'
    ORDER BY id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC QUALTIY CHECKER | Elite Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .stat-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid #f1f5f9; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 20px -5px rgba(0,0,0,0.05); }
        .glass-panel { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); }
        .custom-table tr:last-child { border-bottom: none; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 max-w-7xl mx-auto gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center gap-3">
                    <div class="p-2 bg-indigo-600 rounded-xl shadow-lg shadow-indigo-100">
                        <span class="material-icons-round text-white">fact_check</span>
                    </div>
                    Quality Checker
                </h1>
                <p class="text-slate-500 mt-1 font-medium">Hello, <span class="text-indigo-600 font-bold"><?= $_SESSION['username']; ?></span>. Ready for quality check?</p>
            </div>
            
            <div class="flex items-center gap-3">
                <!-- 🔔 UNIVERSAL NOTIFICATION BELL + DROPDOWN -->
                <?php include __DIR__ . "/../partials/notification_bell.php"; ?>

                <div class="bg-white px-5 py-3 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-4">
                    <div class="text-right">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-none">Status</p>
                        <p class="text-xs font-bold text-emerald-500 flex items-center gap-1 mt-1">
                            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> Analyzing Live
                        </p>
                    </div>
                    <div class="h-8 w-px bg-slate-100"></div>
                    <p class="text-xs font-bold text-slate-600"><?= date("d M, h:i A"); ?></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 mb-10 max-w-7xl mx-auto">
            
            <div class="stat-card bg-white p-6 rounded-[2rem]">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl">
                        <span class="material-icons-round text-xl">pending_actions</span>
                    </div>
                    <span class="text-[10px] font-black text-slate-300 uppercase">Pending</span>
                </div>
                <h2 class="text-4xl font-black text-slate-800 tracking-tight"><?= $pending ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem]">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2.5 bg-emerald-50 text-emerald-600 rounded-xl">
                        <span class="material-icons-round text-xl">task_alt</span>
                    </div>
                    <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest">Approved</span>
                </div>
                <h2 class="text-4xl font-black text-emerald-600 tracking-tight"><?= $approved ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem]">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2.5 bg-rose-50 text-rose-600 rounded-xl">
                        <span class="material-icons-round text-xl">history_edu</span>
                    </div>
                    <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest">Rework</span>
                </div>
                <h2 class="text-4xl font-black text-rose-600 tracking-tight"><?= $rework ?></h2>
            </div>

            <div class="stat-card bg-white p-6 rounded-[2rem] border-orange-100">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-2.5 bg-orange-50 text-orange-600 rounded-xl">
                        <span class="material-icons-round text-xl">emergency_share</span>
                    </div>
                    <span class="text-[10px] font-black text-orange-300 uppercase tracking-widest">Urgent</span>
                </div>
                <h2 class="text-4xl font-black text-orange-600 tracking-tight animate-pulse"><?= $urgent ?></h2>
            </div>

            <div class="stat-card bg-slate-900 p-6 rounded-[2rem] flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-2.5 bg-slate-800 text-purple-400 rounded-xl">
                            <span class="material-icons-round text-xl">menu_book</span>
                        </div>
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Reviewed</span>
                    </div>
                    <h2 class="text-2xl font-black text-white tracking-tight"><?= number_format($totalWords) ?></h2>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-800 flex items-center justify-between text-[11px]">
                    <span class="text-slate-400 font-bold"><?= date('M') ?> Month:</span>
                    <span class="font-extrabold text-purple-300 bg-purple-950/80 px-2 py-0.5 rounded-full"><?= number_format($monthlyWords) ?></span>
                </div>
            </div>
        </div>

        <!-- ===================== 📅 DAILY TASK SECTION (DATE-WISE BREAKDOWN) ===================== -->
        <div class="bg-white p-6 sm:p-8 rounded-[2.5rem] shadow-sm border border-slate-100 mb-10 max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 pb-4 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                        <span class="material-icons-round text-xl">calendar_today</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-800">Daily QC Task Tracker</h3>
                            <span class="text-xs font-bold text-slate-400">• <?= date('d M Y', strtotime($dashDate)) ?></span>
                            <?php if ($dashDate === date('Y-m-d')): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider">Today</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500">View tasks submitted & verified on any date</p>
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
                        Full QC Hub <span class="material-icons-round text-sm">arrow_forward</span>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Total Day Load</span>
                    <p class="text-lg font-extrabold text-slate-800"><?= $dailyStats['total'] ?> Tasks</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-purple-500">In QC Review</span>
                    <p class="text-lg font-extrabold text-purple-600"><?= $dailyStats['qc'] ?> Tasks</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-emerald-500">Approved</span>
                    <p class="text-lg font-extrabold text-emerald-600"><?= $dailyStats['approved'] ?> Passed</p>
                </div>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <span class="text-[10px] font-black uppercase tracking-wider text-indigo-500">Volume</span>
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
                                <?= $dt['task_key'] ?> · <?= number_format($dt['word_count'] ?? 0) ?> words · Expert: <?= htmlspecialchars($dt['expert_name'] ?? 'Unassigned') ?>
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

                        <?php if (($_SESSION['role'] ?? '') === 'operations_manager'): ?>
                        <button type="button" onclick="openOmReuploadModal(<?= $dt['id'] ?>, '<?= htmlspecialchars($dt['task_key'], ENT_QUOTES) ?>')"
                                class="px-2 py-1 bg-amber-50 text-amber-700 hover:bg-amber-500 hover:text-white rounded-lg text-[10px] font-bold transition-all flex items-center gap-0.5"
                                title="OM: Edit/Re-upload Solution File (Clear & Sync Drive)">
                            <span class="material-icons-round text-xs">drive_file_rename_outline</span> Edit
                        </button>

                        <?php if (!empty($dt['team_file'])): ?>
                        <button type="button" onclick="confirmOmDeleteFile(<?= $dt['id'] ?>, '<?= htmlspecialchars($dt['task_key'], ENT_QUOTES) ?>')"
                                class="px-2 py-1 bg-rose-50 text-rose-700 hover:bg-rose-600 hover:text-white rounded-lg text-[10px] font-bold transition-all flex items-center gap-0.5"
                                title="OM: Delete Solution File from Local & Drive">
                            <span class="material-icons-round text-xs">delete_forever</span> Delete
                        </button>
                        <?php endif; ?>
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
                <span class="material-icons-round text-3xl text-slate-300 mb-1">fact_check</span>
                <p class="text-xs font-semibold">No tasks recorded on <?= date('d M Y', strtotime($dashDate)) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10 max-w-7xl mx-auto">
            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2 uppercase tracking-widest text-xs">
                    <span class="material-icons-round text-indigo-500">pie_chart</span> Status Analytics
                </h3>
                <div id="qcStatusChart"></div>
            </div>

            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2 uppercase tracking-widest text-xs">
                    <span class="material-icons-round text-purple-500">stacked_bar_chart</span> Volumetric Review
                </h3>
                <div id="wordsChart"></div>
            </div>
        </div>

        <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden max-w-7xl mx-auto">
            <div class="p-8 border-b border-slate-50 flex justify-between items-center">
                <h3 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="material-icons-round text-slate-400">history</span> Recent Audits
                </h3>
                <span class="text-[10px] font-black bg-slate-100 text-slate-500 px-3 py-1 rounded-full uppercase tracking-widest">Last 5 Actions</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left custom-table">
                    <thead>
                        <tr class="text-[11px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50">
                            <th class="px-8 py-5">Task Identity</th>
                            <th class="px-8 py-5 text-center">Volume</th>
                            <th class="px-8 py-5 text-center">Artifacts</th>
                            <th class="px-8 py-5">Reviewer Notes</th>
                            <th class="px-8 py-5 text-right">State</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($recent as $r): ?>
                        <tr class="group hover:bg-slate-50/50 transition-all">
                            <td class="px-8 py-5 font-bold text-slate-700 text-sm italic"><?= htmlspecialchars($r['task_key']) ?></td>
                            <td class="px-8 py-5 text-center font-bold text-slate-500 text-sm"><?= number_format($r['word_count']) ?> <span class="text-[9px] font-normal italic">wds</span></td>
                            <td class="px-8 py-5 text-center">
                                <?php if(!empty($r['drive_folder_link'])): ?>
                                    <!-- GOOGLE DRIVE -->
                                    <a href="<?= htmlspecialchars($r['drive_folder_link']) ?>" target="_blank" title="Google Drive" class="inline-flex items-center justify-center h-8 w-8 bg-indigo-50 text-indigo-600 rounded-lg hover:bg-indigo-600 hover:text-white transition-all">
                                        <span class="material-icons-round text-sm">folder_open</span>
                                    </a>
                                <?php else: ?>
                                    <span class="text-[9px] font-bold text-slate-300 uppercase tracking-widest">Not synced</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-8 py-5 text-xs text-slate-500 font-medium max-w-[200px] truncate italic">"<?= $r['qc_notes'] ? htmlspecialchars($r['qc_notes']) : 'No notes' ?>"</td>
                            <td class="px-8 py-5 text-right">
                                <?php 
                                    $col = $r['status'] == 'rework' ? 'rose' : 'emerald';
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

<script>
    // Status Chart
    new ApexCharts(document.querySelector("#qcStatusChart"), {
        chart: { type: 'donut', height: 320, fontFamily: 'Plus Jakarta Sans' },
        series: [<?= $approved ?>, <?= $rework ?>, <?= $pending ?>],
        labels: ['Approved', 'Rework', 'Pending'],
        colors: ['#10b981', '#f43f5e', '#6366f1'],
        stroke: { show: false },
        dataLabels: { enabled: false },
        legend: { position: 'bottom', fontWeight: 700, labels: { colors: '#64748b' } },
        plotOptions: { pie: { donut: { size: '75%', labels: { show: true, total: { show: true, label: 'Total Audits', fontSize: '12px', fontWeight: 800 } } } } }
    }).render();

    // Volume Chart
    new ApexCharts(document.querySelector("#wordsChart"), {
        chart: { type: 'bar', height: 320, fontFamily: 'Plus Jakarta Sans', toolbar: { show: false } },
        series: [{ name: 'Words Reviewed', data: [<?= $totalWords ?>] }],
        xaxis: { categories: ['Total Effort'], axisBorder: { show: false }, axisTicks: { show: false } },
        colors: ['#8b5cf6'],
        grid: { borderColor: '#f1f5f9' },
        plotOptions: { bar: { borderRadius: 12, columnWidth: '30%', distributed: true } },
        dataLabels: { enabled: false }
    }).render();
</script>

<!-- ========================================================
     OM EDIT / RE-UPLOAD SOLUTION FILE MODAL (OPERATIONS MANAGER)
     ======================================================== -->
<?php if(($_SESSION['role'] ?? '') === 'operations_manager'): ?>
<div id="omReuploadModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-100 relative animate-in fade-in zoom-in-95 duration-150">
        <button type="button" onclick="closeOmReuploadModal()" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center">
            <span class="material-icons-round text-lg">close</span>
        </button>

        <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <span class="material-icons-round text-2xl">drive_file_rename_outline</span>
            </div>
            <div>
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-600 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200">Operations Manager Action</span>
                <h3 class="text-lg font-black text-slate-800 tracking-tight mt-0.5">Edit & Replace Solution File</h3>
                <p id="omModalTaskKey" class="text-xs font-bold text-slate-400 uppercase tracking-wider">TASK-KEY</p>
            </div>
        </div>

        <p class="text-xs text-slate-500 leading-relaxed mb-5">
            Select the new/corrected solution file (e.g. corrected spacing, revised document). 
            <strong class="text-slate-700">The previous files in Google Drive's "Operations Files" subfolder will be cleared and replaced with this latest file automatically.</strong>
        </p>

        <form id="omReuploadForm" onsubmit="submitOmReupload(event)" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" id="omReuploadTaskId" name="task_id" value="">
            <input type="hidden" name="action" value="reupload_file">

            <div>
                <label class="block text-xs font-extrabold uppercase text-slate-600 mb-1.5">Select Solution File(s) *</label>
                <input type="file" name="solution_files[]" multiple required
                       class="w-full text-xs font-semibold text-slate-700 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-slate-200 rounded-2xl p-2 cursor-pointer bg-slate-50">
                <p class="text-[11px] text-slate-400 mt-1">Accepts .docx, .pdf, .zip, .xlsx or multiple files</p>
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase text-slate-600 mb-1.5">Operations Notes (Optional)</label>
                <textarea name="om_notes" rows="2" placeholder="e.g. Fixed spacing and references as discussed"
                          class="w-full text-xs font-medium border border-slate-200 rounded-2xl p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-50"></textarea>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="button" onclick="closeOmReuploadModal()" class="w-1/3 py-3 rounded-xl text-xs font-extrabold text-slate-500 hover:bg-slate-100 transition">
                    Cancel
                </button>
                <button type="submit" id="omSubmitBtn" class="w-2/3 py-3 rounded-xl text-xs font-extrabold bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-200 transition flex items-center justify-center gap-1.5">
                    <span class="material-icons-round text-base">cloud_upload</span>
                    <span>Upload & Replace</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openOmReuploadModal(taskId, taskKey) {
    document.getElementById('omReuploadTaskId').value = taskId;
    document.getElementById('omModalTaskKey').textContent = taskKey;
    document.getElementById('omReuploadForm').reset();
    document.getElementById('omReuploadModal').classList.remove('hidden');
}

function closeOmReuploadModal() {
    document.getElementById('omReuploadModal').classList.add('hidden');
}

async function submitOmReupload(e) {
    e.preventDefault();
    const btn = document.getElementById('omSubmitBtn');
    const form = document.getElementById('omReuploadForm');
    const fd = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = `<span class="material-icons-round text-base animate-spin">refresh</span> Uploading & Syncing Drive...`;

    try {
        const res = await fetch('om_file_action.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'File Replaced!',
                text: data.message,
                confirmButtonColor: '#4f46e5',
                customClass: { popup: 'rounded-[2rem]' }
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Upload Failed',
                text: data.error || 'Could not replace file',
                confirmButtonColor: '#e11d48',
                customClass: { popup: 'rounded-[2rem]' }
            });
        }
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: err.message || 'Network error occurred',
            confirmButtonColor: '#e11d48',
            customClass: { popup: 'rounded-[2rem]' }
        });
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<span class="material-icons-round text-base">cloud_upload</span><span>Upload & Replace</span>`;
    }
}

function confirmOmDeleteFile(taskId, taskKey) {
    Swal.fire({
        title: 'Delete Solution File?',
        html: `Are you sure you want to delete the operations solution file for <strong>${taskKey}</strong>?<br><br><span class="text-xs text-rose-600 font-bold">This will remove the file from both local storage and the Google Drive Operations folder.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Delete File',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2rem]' }
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                text: 'Removing file from local and Google Drive',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const fd = new FormData();
                fd.append('action', 'delete_file');
                fd.append('task_id', taskId);

                const res = await fetch('om_file_action.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: data.message,
                        confirmButtonColor: '#4f46e5',
                        customClass: { popup: 'rounded-[2rem]' }
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Delete Failed',
                        text: data.error || 'Could not delete file',
                        confirmButtonColor: '#e11d48',
                        customClass: { popup: 'rounded-[2rem]' }
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: err.message,
                    confirmButtonColor: '#e11d48',
                    customClass: { popup: 'rounded-[2rem]' }
                });
            }
        }
    });
}
</script>
<?php endif; ?>

</body>
</html>