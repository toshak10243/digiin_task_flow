<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');
require_once "../../app/config/database.php";

$db = (new Database())->connect();
$user_id = $_SESSION['user_id'];

// Query: Sirf wahi tasks fetch honge jo is Expert ko assigned hain
$query = "
    SELECT t.* 
    FROM tasks t
    WHERE t.assigned_to = :user_id 
    AND t.additional_files IS NOT NULL
";

// Apply Date Filter
if(!empty($_GET['filter_date'])){
    $fDate = $_GET['filter_date'];
    $query .= " AND DATE(t.task_created_at) = '$fDate'";
}

$query .= " ORDER BY t.id DESC";
$stmt = $db->prepare($query);
$stmt->execute([':user_id' => $user_id]);
$extraTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Materials Repository | Expert Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .repo-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid #e2e8f0; }
        .repo-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05); border-color: #4f46e5; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 max-w-7xl mx-auto gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <div class="p-3 bg-indigo-600 rounded-2xl shadow-lg shadow-indigo-100">
                        <span class="material-icons-round text-white">folder_special</span>
                    </div>
                    Extra Assets Repository
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Access supplemental materials for your assigned tasks.</p>
            </div>
            
            <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-2xl border border-slate-200 shadow-sm">
                <span class="material-icons-round text-indigo-500">analytics</span>
                <span class="text-sm font-black text-slate-700 uppercase tracking-tighter">Total Extra Briefs: <?= count($extraTasks) ?></span>
            </div>
        </div>

        <!-- Search & Filter Bar -->
        <div class="bg-white p-4 rounded-3xl shadow-sm border border-slate-100 flex flex-wrap gap-4 items-center mb-8 max-w-7xl mx-auto">
            <div class="relative flex-1 min-w-[300px]">
                <span class="material-icons-round absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                <input type="text" id="repoSearch" 
                       placeholder="Search by Task Key or Client Name..." 
                       class="w-full pl-12 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-bold outline-none focus:border-indigo-500 transition-all">
            </div>
            
            <form class="flex gap-2">
                <input type="date" name="filter_date" value="<?= $_GET['filter_date'] ?? '' ?>"
                       class="bg-slate-50 border border-slate-200 px-4 py-3 rounded-2xl text-xs font-black text-slate-600 outline-none">
                <button type="submit" class="bg-slate-900 hover:bg-indigo-600 text-white px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest transition-all">
                    Filter
                </button>
            </form>
        </div>

        <!-- Repository Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-7xl mx-auto" id="repoGrid">
            <?php foreach($extraTasks as $task): ?>
            <div class="repo-card bg-white p-8 rounded-[2.5rem] flex flex-col justify-between h-full relative overflow-hidden group">
                
                <div class="relative z-10">
                    <div class="flex justify-between items-start mb-6">
                        <div class="px-3 py-1.5 bg-indigo-50 text-indigo-600 rounded-xl text-[10px] font-black uppercase tracking-widest border border-indigo-100">
                            <?= $task['task_key'] ?>
                        </div>
                        <span class="flex items-center gap-1 text-[10px] font-bold text-slate-400 italic">
                            <span class="material-icons-round text-xs text-rose-500">timer</span>
                            <?= date("d M, h:i A", strtotime($task['deadline'])) ?>
                        </span>
                    </div>

                    <h3 class="text-lg font-extrabold text-slate-800 leading-tight mb-4 group-hover:text-indigo-600 transition-colors">
                        <?= htmlspecialchars($task['task_name']) ?>
                    </h3>

                    <!-- Project Info Stack -->
                    <div class="space-y-4 mb-8">
                        <div class="flex items-center justify-between border-b border-slate-50 pb-3">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-tighter">Client Identity</span>
                            <span class="text-xs font-bold text-emerald-600 italic"><?= htmlspecialchars($task['client_name']) ?></span>
                        </div>
                        <div class="flex items-center justify-between border-b border-slate-50 pb-3">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-tighter">Status</span>
                            <span class="text-[10px] font-black text-indigo-600 uppercase"><?= str_replace('_', ' ', $task['status']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="relative z-10 mt-auto pt-4">
                    <?php if(!empty($task['additional_files'])): ?>
                        <!-- [MODIFIED] Google Drive migration — local ZIP download removed.
                             additional_files now stores the Drive folder URL directly. -->
                        <a href="<?= htmlspecialchars($task['additional_files']) ?>" target="_blank"
                           class="w-full py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3 hover:bg-emerald-600 transition-all shadow-xl shadow-slate-100">
                            Additional Assets <span class="material-icons-round text-sm text-amber-400">folder_open</span>
                        </a>
                    <?php else: ?>
                        <button type="button" disabled
                           class="w-full py-4 bg-slate-100 text-slate-400 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] flex items-center justify-center gap-3 cursor-not-allowed">
                            No Additional Assets Available
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <?php if(count($extraTasks) == 0): ?>
        <div class="flex flex-col items-center justify-center p-20 bg-white rounded-[3rem] border-2 border-dashed border-slate-100 mt-10 max-w-7xl mx-auto">
            <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                <span class="material-icons-round text-slate-200 text-4xl">inventory_2</span>
            </div>
            <p class="text-slate-400 font-bold uppercase text-xs tracking-widest">No extra briefs found for your tasks</p>
        </div>
        <?php endif; ?>

    </main>
</div>

<script>
// Live Search
document.getElementById('repoSearch').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let cards = document.querySelectorAll('.repo-card');
    
    cards.forEach(card => {
        let text = card.innerText.toLowerCase();
        card.style.display = text.includes(filter) ? "flex" : "none";
    });
});
</script>

</body>
</html>