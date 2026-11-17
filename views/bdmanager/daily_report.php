<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bdmanager');

require_once "../../app/config/database.php";
$db = (new Database())->connect();

$date = $_GET['date'] ?? date("Y-m-d");

$stmt = $db->prepare("
    SELECT t.*, u.name AS team_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE DATE(t.task_created_at)=:date
    ORDER BY t.id ASC
");
$stmt->execute([':date'=>$date]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BDM Daily Report | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .report-container { background: #ffffff; border-radius: 1.5rem; border: 1px solid #e2e8f0; overflow: hidden; }
        /* Professional Grid Borders for PNG/View */
        .grid-table th, .grid-table td { border: 1px solid #e2e8f0; padding: 12px 16px; }
        .grid-table th { background-color: #f8fafc; color: #475569; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 800; }
        .action-btn { transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 space-y-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <span class="p-2 bg-indigo-600 rounded-xl shadow-lg">
                        <span class="material-icons-round text-white">summarize</span>
                    </span>
                    Daily Task Report
                </h1>
                <p class="text-slate-500 font-medium mt-1">Date-wise analytical breakdown of all project tasks.</p>
            </div>
            
            <div class="bg-white p-2 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-2">
                <form method="GET" class="flex items-center gap-2">
                    <input type="date" name="date" value="<?= $date ?>" 
                           class="bg-slate-50 px-4 py-2 rounded-xl text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500">
                    <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-xl text-sm font-bold hover:bg-indigo-700 transition-all">
                        Search
                    </button>
                </form>
            </div>
        </div>

        <div class="flex flex-wrap gap-4 items-center">
            <a href="../../app/controllers/BDMReportController.php?date=<?= $date ?>" 
               class="action-btn bg-emerald-600 text-white px-6 py-3 rounded-2xl shadow-lg shadow-emerald-100">
                <span class="material-icons-round text-sm">file_download</span> Download Excel
            </a>

            <button onclick="downloadPNG()" 
                    class="action-btn bg-purple-600 text-white px-6 py-3 rounded-2xl shadow-lg shadow-purple-100">
                <span class="material-icons-round text-sm">image</span> Download PNG
            </button>
        </div>

        <div id="reportTable" class="report-container shadow-sm p-4 bg-white">
            <div class="flex justify-between items-center mb-6 p-4 border-b border-slate-50">
                <div>
                    <h2 class="text-xl font-black text-slate-800">Digiink Workflow Report</h2>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Report Date: <?= date("d F, Y", strtotime($date)) ?></p>
                </div>
                <div class="text-right">
                    <span class="text-[10px] font-black bg-indigo-50 text-indigo-600 px-3 py-1 rounded-full uppercase">Verified System Data</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full grid-table text-left border-collapse">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Task Key</th>
                            <th>Assigned To</th>
                            <th class="text-center">Word Count</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php if(empty($tasks)): ?>
                            <tr>
                                <td colspan="5" class="p-20 text-center text-slate-300 font-bold uppercase tracking-widest">No data available for this date</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach($tasks as $t): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="font-bold text-slate-500"><?= date("d/m/Y", strtotime($t['task_created_at'])) ?></td>
                            <td class="font-extrabold text-indigo-600 italic uppercase"><?= $t['task_key'] ?></td>
                            <td class="font-semibold text-slate-700">
                                <span class="flex items-center gap-1.5">
                                    <span class="material-icons-round text-xs text-slate-300">person</span>
                                    <?= $t['team_name'] ?? '<span class="text-slate-300 italic font-normal">Not Assigned</span>' ?>
                                </span>
                            </td>
                            <td class="text-center font-black text-slate-600"><?= number_format($t['word_count']) ?></td>
                            <td class="text-center">
                                <span class="px-3 py-1 bg-slate-100 text-slate-700 rounded-lg text-[10px] font-black uppercase tracking-widest">
                                    <?= $t['status'] ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-6 p-4 text-[10px] text-slate-300 font-medium italic text-right">
                Generated via Digiink Task Flow Portal on <?= date("d/m/Y H:i") ?>
            </div>
        </div>

    </main>
</div>

<script>
function downloadPNG(){
    // html2canvas logic as it is (no logic change)
    html2canvas(document.getElementById("reportTable"), {
        backgroundColor: "#ffffff",
        scale: 2 // Quality high karne ke liye
    }).then(canvas=>{
        let link=document.createElement("a");
        link.download="Digiink_Daily_Report_<?= $date ?>.png";
        link.href=canvas.toDataURL("image/png");
        link.click();
    });
}
</script>

</body>
</html>