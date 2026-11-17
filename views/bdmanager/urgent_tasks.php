<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bdmanager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

/* DATE FILTER LOGIC */
$filterDate = $_GET['filter_date'] ?? '';

$query = "SELECT t.*, 
            b.name AS bde_name,
            b.username AS bde_user,
            tm.name AS team_name,
            tm.username AS team_user
    FROM tasks t
    JOIN users b ON t.created_by = b.id
    LEFT JOIN users tm ON t.assigned_to = tm.id
    WHERE t.urgent='yes'";

if($filterDate){
    $start = $filterDate . " 00:00:00";
    $end   = $filterDate . " 23:59:59";
    $query .= " AND t.task_created_at BETWEEN '$start' AND '$end'";
}

$query .= " ORDER BY t.deadline ASC";
$tasks = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Urgent Tasks Hub | BDM</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #fcfcfd; }
    .urgent-card { 
        background: #ffffff; 
        border: 1px solid #fee2e2; 
        border-radius: 24px; 
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    .urgent-card:hover { transform: translateY(-5px); box-shadow: 0 20px 30px -10px rgba(220, 38, 38, 0.1); }
    .urgent-stripe {
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 6px;
        background: linear-gradient(to bottom, #dc2626, #ef4444);
    }
    .search-input:focus { border-color: #ef4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1); outline: none; }
</style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 space-y-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <span class="p-2 bg-rose-600 rounded-xl shadow-lg shadow-rose-200">
                        <span class="material-icons-round text-white">priority_high</span>
                    </span>
                    Urgent Tasks
                </h1>
                <p class="text-slate-500 font-medium mt-1 uppercase text-xs tracking-widest italic">Critical priority assignments monitoring</p>
            </div>
            <div class="bg-white px-5 py-2.5 rounded-2xl shadow-sm border border-slate-200 text-sm font-bold text-slate-700">
                <?= date("d M Y, h:i A"); ?>
            </div>
        </div>

        <div class="bg-white p-3 rounded-2xl shadow-sm border border-slate-100 flex flex-wrap gap-4 items-center">
            <div class="relative flex-1 min-w-[280px]">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                <input type="text" id="taskSearch" placeholder="Search by task key, name, BDE or Team..." 
                    class="search-input w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium transition-all">
            </div>

            <form method="GET" class="flex gap-2">
                <input type="date" name="filter_date" value="<?= $filterDate ?>" 
                    class="search-input px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none">
                <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white px-6 py-2.5 rounded-xl text-sm font-bold transition-all">
                    Filter
                </button>
                <?php if($filterDate): ?>
                    <a href="urgent_tasks.php" class="bg-slate-200 text-slate-600 px-4 py-2.5 rounded-xl text-sm font-bold">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if(empty($tasks)): ?>
            <div class="bg-white p-20 rounded-[2.5rem] shadow-sm border border-dashed border-slate-300 text-center">
                <span class="material-icons-round text-slate-200 text-7xl mb-4">task_alt</span>
                <p class="text-slate-400 font-bold uppercase tracking-widest">No urgent tasks right now 🎉</p>
            </div>
        <?php else: ?>

        <div class="space-y-6" id="tasksContainer">

            <?php foreach($tasks as $t): ?>
            <div class="urgent-card p-6 data-card">
                <div class="urgent-stripe"></div>

                <div class="flex flex-col md:flex-row justify-between items-start border-b border-slate-50 pb-4 mb-5 gap-4">
                    <div>
                        <h2 class="text-xl font-black text-slate-800 tracking-tight flex items-center gap-2">
                            <span class="text-rose-600 italic uppercase"><?= $t['task_key'] ?></span>
                            <span class="text-slate-300 font-light">|</span>
                            <?= $t['task_name'] ?>
                        </h2>
                        <p class="text-xs font-bold text-slate-400 mt-1 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-icons-round text-sm">person</span> Created by 
                            <span class="text-slate-700 font-black italic lowercase"><?= $t['bde_name']; ?></span> 
                            <span class="text-slate-400">(<?= $t['bde_user']; ?>)</span>
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <span class="bg-rose-600 text-white px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest animate-pulse">
                            URGENT
                        </span>

                        <?php if($t['status']=='completed'): ?>
                            <span class="bg-emerald-500 text-white px-3 py-1 rounded-lg text-[10px] font-black uppercase">
                                COMPLETED
                            </span>
                        <?php elseif($t['status']=='rework'): ?>
                            <span class="bg-amber-500 text-white px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest">
                                REWORK
                            </span>
                        <?php else: ?>
                            <span class="bg-slate-800 text-white px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest">
                                <?= $t['status'] ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-y-6 gap-x-4">

                    <div>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Word Count</p>
                        <p class="text-sm font-extrabold text-slate-700"><?= number_format($t['word_count']); ?></p>
                    </div>

                    <div>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Current Stage</p>
                        <p class="text-sm font-bold text-indigo-600">
                            <?= ucfirst(str_replace('_',' ',$t['current_stage'])); ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Status</p>
                        <p class="text-sm font-bold text-slate-700 capitalize"><?= $t['status']; ?></p>
                    </div>

                    <div>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Created On</p>
                        <p class="text-sm font-medium text-slate-600 italic"><?= date("d M Y, h:i A", strtotime($t['task_created_at'])); ?></p>
                    </div>

                    <div>
                        <p class="text-rose-500 text-[10px] font-black uppercase tracking-widest mb-1">Deadline</p>
                        <p class="text-sm font-black text-rose-600 flex items-center gap-1">
                            <span class="material-icons-round text-xs">alarm</span>
                            <?= date("d M Y, h:i A", strtotime($t['deadline'])); ?>
                        </p>
                    </div>

                    <?php if(!empty($t['drive_folder_link'])): ?>
                        <!-- [MODIFIED] Google Drive migration — local file_path/zip_viewer
                             ("Initial File") removed. Opens the task's Drive folder. -->
                        <div>
                            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Client Files</p>
                            <a href="<?= htmlspecialchars($t['drive_folder_link']); ?>" target="_blank"
                               class="inline-flex items-center gap-1 text-blue-600 font-bold hover:underline text-xs">
                                <span class="material-icons-round text-sm">folder_open</span> View Assets
                            </a>
                        </div>

                        <!-- [MODIFIED] Google Drive migration — local team_file link
                             ("Final Submission") removed. Opens the same Drive folder
                             (Operations Files live inside it). -->
                        <div>
                            <p class="text-emerald-600 text-[10px] font-black uppercase tracking-widest mb-1">Operations Files</p>
                            <a href="<?= htmlspecialchars($t['drive_folder_link']); ?>" target="_blank"
                               class="inline-flex items-center gap-1 text-emerald-600 font-bold hover:underline text-xs">
                                <span class="material-icons-round text-sm">folder_open</span> View
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="col-span-2">
                            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Google Drive</p>
                            <span class="text-slate-300 text-xs italic">Not synced with Google Drive</span>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
            <?php endforeach; ?>

        </div>

        <?php endif; ?>

    </main>
</div>

<script>
    document.getElementById('taskSearch').addEventListener('keyup', function() {
        let searchValue = this.value.toLowerCase();
        let cards = document.querySelectorAll('.data-card');

        cards.forEach(card => {
            let text = card.textContent.toLowerCase();
            card.style.display = text.includes(searchValue) ? '' : 'none';
        });
    });
</script>

</body>
</html>