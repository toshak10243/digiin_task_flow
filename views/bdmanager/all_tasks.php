<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bdmanager');

require_once "../../app/config/database.php";
$db = (new Database())->connect();

/* DATE FILTER LOGIC */
$filterDate = $_GET['filter_date'] ?? '';

$query = "SELECT t.*, 
            b.username AS bde_user,
            tm.username AS team_user
    FROM tasks t
    JOIN users b ON t.created_by = b.id
    LEFT JOIN users tm ON t.assigned_to = tm.id";

if($filterDate){
    $start = $filterDate . " 00:00:00";
    $end   = $filterDate . " 23:59:59";
    $query .= " WHERE t.task_created_at BETWEEN '$start' AND '$end'";
}

$query .= " ORDER BY t.id DESC";
$tasks = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Tasks Overview | BDM Panel</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    .table-container { border: 1px solid #e2e8f0; border-radius: 1.5rem; overflow: hidden; background: #fff; }
    .data-row { transition: all 0.2s; border-bottom: 1px solid #f1f5f9; }
    .data-row:hover { background-color: #f8fafc; }
    th, td { border: 1px solid #f1f5f9; }
    .custom-scrollbar::-webkit-scrollbar { height: 6px; width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
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
                    <span class="p-2 bg-indigo-600 rounded-xl shadow-lg">
                        <span class="material-icons-round text-white">inventory</span>
                    </span>
                    All Tasks Overview
                </h1>
                <p class="text-slate-500 font-medium mt-1 uppercase text-xs tracking-widest italic">Global assignment monitoring for managers</p>
            </div>
            <div class="bg-white px-5 py-2.5 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-3">
                <span class="text-sm font-bold text-slate-700"><?= date("d M Y, h:i A"); ?></span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-[1.5rem] shadow-sm border border-slate-100 flex flex-wrap gap-4 items-center">
            <div class="relative flex-1 min-w-[300px]">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                <input type="text" id="taskSearch" placeholder="Search task key, BDE, or status..." 
                    class="search-input w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium transition-all">
            </div>

            <form method="GET" class="flex items-center gap-2">
                <input type="date" name="filter_date" value="<?= $filterDate ?>" 
                    class="search-input px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 outline-none">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2">
                    <span class="material-icons-round text-sm">filter_list</span> Filter
                </button>
                <?php if($filterDate): ?>
                    <a href="all_tasks.php" class="text-slate-400 hover:text-rose-500 p-2">
                        <span class="material-icons-round">cancel</span>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-container shadow-sm overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar">
                <table id="taskTable" class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[11px] font-black text-slate-500 uppercase tracking-widest">
                            <th class="p-4">Task Key</th>
                            <th class="p-4">BDE</th>
                            <th class="p-4 text-center">Words</th>
                            <th class="p-4 text-center">Stage</th>
                            <th class="p-4 text-center">Status</th>
                            <th class="p-4 text-center">Urgent</th>
                            <th class="p-4">Deadline</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm" id="taskTableBody">
                        <?php if(count($tasks)==0): ?>
                        <tr>
                            <td colspan="8" class="text-center p-20 text-slate-400 font-bold uppercase tracking-widest">
                                <span class="material-icons-round block text-5xl mb-2 opacity-20">assignment_late</span>
                                No tasks available in records
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach($tasks as $t): ?>
                        <tr class="data-row">
                            <td class="p-4 font-extrabold text-indigo-600 italic tracking-tight uppercase">
                                <?= $t['task_key'] ?>
                            </td>

                            <td class="p-4 font-bold text-slate-700 lowercase">
                                <span class="flex items-center gap-1.5">
                                    <span class="material-icons-round text-sm text-slate-300">person</span>
                                    <?= $t['bde_user'] ?>
                                </span>
                            </td>

                            <td class="p-4 text-center font-black text-slate-600">
                                <?= number_format($t['word_count']) ?>
                            </td>

                            <td class="p-4 text-center">
                                <span class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest bg-purple-50 text-purple-600 border border-purple-100">
                                    <?= str_replace('_',' ',$t['current_stage']) ?>
                                </span>
                            </td>

                            <td class="p-4 text-center">
                                <?php 
                                    $s_color = ($t['status'] == 'completed') ? 'emerald' : (($t['status'] == 'rework') ? 'rose' : 'blue');
                                ?>
                                <span class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest bg-<?= $s_color ?>-50 text-<?= $s_color ?>-600 border border-<?= $s_color ?>-100">
                                    <?= $t['status'] ?>
                                </span>
                            </td>

                            <td class="p-4 text-center">
                                <?php if($t['urgent']=='yes'): ?>
                                    <span class="bg-rose-100 text-rose-700 px-3 py-1 rounded-lg text-[10px] font-black uppercase animate-pulse">Yes</span>
                                <?php else: ?>
                                    <span class="text-slate-300 font-bold uppercase text-[10px]">No</span>
                                <?php endif; ?>
                            </td>

                            <td class="p-4 font-bold text-slate-400 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="material-icons-round text-xs text-rose-400">alarm</span>
                                    <?= date("d M, H:i", strtotime($t['deadline'])) ?>
                                </div>
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
    document.getElementById('taskSearch').addEventListener('keyup', function() {
        let searchValue = this.value.toLowerCase();
        let rows = document.querySelectorAll('#taskTableBody tr.data-row');

        rows.forEach(row => {
            let text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchValue) ? '' : 'none';
        });
    });
</script>

</body>
</html>