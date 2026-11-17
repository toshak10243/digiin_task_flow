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
           b.username AS bde_user,
           tm.username AS team_user
    FROM tasks t
    JOIN users b ON t.created_by = b.id
    LEFT JOIN users tm ON t.assigned_to = tm.id
    WHERE t.status='completed'";

if($filterDate){
    $start = $filterDate . " 00:00:00";
    $end   = $filterDate . " 23:59:59";
    $query .= " AND t.task_created_at BETWEEN '$start' AND '$end'";
}

$query .= " ORDER BY t.id DESC";
$tasks = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Completed Tasks | BDM Panel</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    .task-card { 
        background: #ffffff; 
        border: 1px solid #e2e8f0; 
        border-radius: 20px; 
        transition: all 0.3s ease;
    }
    .task-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .search-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); outline: none; }
</style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 space-y-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <span class="p-2 bg-emerald-600 rounded-xl shadow-lg shadow-emerald-100">
                        <span class="material-icons-round text-white">verified</span>
                    </span>
                    Completed Tasks
                </h1>
                <p class="text-slate-500 font-medium mt-1 uppercase text-xs tracking-widest italic">Archived project history and deliverables</p>
            </div>
            <div class="bg-white px-5 py-2.5 rounded-2xl shadow-sm border border-slate-200 text-sm font-bold text-slate-700">
                <?= date("d M Y"); ?>
            </div>
        </div>

        <div class="bg-white p-3 rounded-2xl shadow-sm border border-slate-100 flex flex-wrap gap-4 items-center">
            <div class="relative flex-1 min-w-[280px]">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                <input type="text" id="taskSearch" placeholder="Search task key, name or user..." 
                    class="search-input w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium transition-all">
            </div>

            <form method="GET" class="flex gap-2">
                <input type="date" name="filter_date" value="<?= $filterDate ?>" 
                    class="search-input px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-xl text-sm font-bold transition-all">
                    Filter
                </button>
                <?php if($filterDate): ?>
                    <a href="completed_tasks.php" class="bg-slate-200 text-slate-600 px-4 py-2.5 rounded-xl text-sm font-bold">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="space-y-6" id="tasksContainer">
            <?php if(empty($tasks)): ?>
            <div class="bg-white p-20 rounded-[2.5rem] shadow-sm border border-dashed border-slate-300 text-center">
                <span class="material-icons-round text-slate-200 text-7xl mb-4">inventory_2</span>
                <p class="text-slate-400 font-bold uppercase tracking-widest">No completed tasks found</p>
            </div>
            <?php endif; ?>

            <?php foreach($tasks as $t): ?>
            <div class="task-card p-6 data-card">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center border-b border-slate-50 pb-4 mb-4 gap-4">
                    <div>
                        <h2 class="text-lg font-black text-slate-800 tracking-tight flex items-center gap-2">
                            <span class="text-emerald-600 italic uppercase"><?= $t['task_key'] ?></span>
                            <span class="text-slate-300 font-light">|</span>
                            <?= $t['task_name'] ?>
                        </h2>
                        <div class="flex gap-4 mt-1">
                            <span class="text-[11px] font-bold text-slate-400 flex items-center gap-1 uppercase tracking-wider">
                                <span class="material-icons-round text-sm">person</span> BDE: <span class="text-slate-600"><?= $t['bde_user'] ?></span>
                            </span>
                            <span class="text-[11px] font-bold text-slate-400 flex items-center gap-1 uppercase tracking-wider">
                                <span class="material-icons-round text-sm text-purple-400">psychology</span> Team: <span class="text-slate-600"><?= $t['team_user'] ?? '—' ?></span>
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if($t['urgent']=='yes'): ?>
                            <span class="bg-rose-50 text-rose-600 px-3 py-1 rounded-lg text-[10px] font-black uppercase border border-rose-100">Urgent</span>
                        <?php endif; ?>
                        <span class="bg-emerald-50 text-emerald-600 px-3 py-1 rounded-lg text-[10px] font-black uppercase border border-emerald-100">Completed</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-6 text-[12px]">
                    <div>
                        <p class="text-slate-400 font-bold uppercase text-[10px] tracking-widest">Words:</p>
                        <p class="font-black text-slate-700 mt-0.5"><?= number_format($t['word_count']) ?> Words</p>
                    </div>
                    <div>
                        <p class="text-slate-400 font-bold uppercase text-[10px] tracking-widest">Created:</p>
                        <p class="font-bold text-slate-600 mt-0.5"><?= date("d M Y, H:i", strtotime($t['task_created_at'])) ?></p>
                    </div>
                    <div>
                        <p class="text-rose-400 font-bold uppercase text-[10px] tracking-widest">Deadline:</p>
                        <p class="font-bold text-rose-600 mt-0.5"><?= date("d M Y, H:i", strtotime($t['deadline'])) ?></p>
                    </div>
                    <?php if(!empty($t['drive_folder_link'])): ?>
                        <!-- [MODIFIED] Google Drive migration — local file_path/zip_viewer
                             ("Initial File") removed. Opens the task's Drive folder. -->
                        <div>
                            <p class="text-slate-400 font-bold uppercase text-[10px] tracking-widest">Client Files:</p>
                            <a href="<?= htmlspecialchars($t['drive_folder_link']) ?>" target="_blank"
                               class="flex items-center gap-1 text-blue-600 font-bold hover:underline mt-0.5">
                                <span class="material-icons-round text-sm">folder_open</span> View Assets
                            </a>
                        </div>

                        <!-- [MODIFIED] Google Drive migration — local team_file link
                             ("Final File") removed. Opens the same Drive folder (Operations
                             Files live inside it). -->
                        <div>
                            <p class="text-emerald-500 font-bold uppercase text-[10px] tracking-widest">Operations Files:</p>
                            <a href="<?= htmlspecialchars($t['drive_folder_link']) ?>" target="_blank"
                               class="flex items-center gap-1 text-emerald-600 font-bold hover:underline mt-0.5">
                                <span class="material-icons-round text-sm">folder_open</span> View
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="md:col-span-2">
                            <p class="text-slate-400 font-bold uppercase text-[10px] tracking-widest">Google Drive:</p>
                            <p class="text-slate-300 mt-0.5 italic">Not synced with Google Drive</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

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