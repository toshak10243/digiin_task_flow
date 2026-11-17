<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

/* FILTER - Securely */
$where = "WHERE t.status='completed'";
$params = [];

if(!empty($_GET['search'])){
    $s = $_GET['search'];
    $where .= " AND t.task_key LIKE :search";
    $params[':search'] = "%$s%";
}

$stmt = $db->prepare("SELECT t.*, 
                            u.username AS bde_user,
                            tm.username AS team_user
                     FROM tasks t
                     JOIN users u ON t.created_by=u.id
                     LEFT JOIN users tm ON t.assigned_to=tm.id
                     $where
                     ORDER BY t.id DESC");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive | Completed Tasks</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .success-card { 
            background: white; 
            border: 1px solid #e2e8f0; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .success-card:hover { 
            border-color: #10b981;
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.1);
        }

        /* Google Drive button — same style used in incoming_tasks.php /
           assigned_tasks.php / additional_repository.php (Inter font, zinc chip). */
        .drive-btn { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="max-w-7xl mx-auto mb-10">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <div class="p-3 bg-emerald-500 rounded-2xl shadow-lg shadow-emerald-100">
                            <span class="material-icons-round text-white">verified</span>
                        </div>
                        Delivery Archive
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium italic">Repository of all successfully finalized tasks.</p>
                </div>

                <form class="flex items-center gap-2 w-full md:w-auto">
                    <div class="relative w-full md:w-72">
                        <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                        <input type="text" name="search" value="<?= $_GET['search'] ?? '' ?>" placeholder="Search ID or Key..." 
                               class="w-full pl-10 pr-4 py-3 rounded-2xl border border-slate-200 outline-none focus:ring-4 focus:ring-emerald-50 transition-all text-sm font-medium">
                    </div>
                    <button class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-bold text-sm hover:bg-emerald-600 transition-all shadow-lg">
                        Search
                    </button>
                </form>
            </div>
        </div>

        <div class="max-w-7xl mx-auto space-y-8">
            <?php foreach($tasks as $t): ?>
            <div class="success-card rounded-[2.5rem] overflow-hidden">
                
                <div class="px-8 py-6 border-b border-slate-50 flex flex-wrap justify-between items-center gap-4 bg-white">
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 bg-slate-50 rounded-2xl flex items-center justify-center border border-slate-100 font-black text-slate-400">
                            #<?= $t['id'] ?>
                        </div>
<div>
    <div class="flex items-center gap-2">
        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight"><?= htmlspecialchars($t['task_key']) ?></h2>
        <span class="bg-emerald-50 text-emerald-600 text-[10px] font-black px-2.5 py-1 rounded-lg uppercase tracking-widest border border-emerald-100">Completed</span>
    </div>
    <!-- ✨ Client Name ko Project Name ke saath sleek look mein dalo -->
    <div class="flex items-center gap-2 mt-1">
        <p class="text-sm text-slate-500 font-medium"><?= htmlspecialchars($t['task_name']) ?></p>
        <span class="text-slate-300 text-xs">|</span>
        <span class="flex items-center gap-1 text-indigo-600 font-bold italic text-xs">
            <span class="material-icons-round text-[12px]">person</span>
            Client: <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?>
        </span>
    </div>
</div>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="text-right border-r pr-5 border-slate-100 hidden lg:block">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Team Member</p>
                            <p class="text-sm font-bold text-slate-700"><?= $t['team_user'] ?? '—' ?></p>
                        </div>
                    </div>
                </div>

                <div class="p-8 grid grid-cols-2 md:grid-cols-4 gap-8 bg-white border-b border-slate-50">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">University & Subject</label>
                        <p class="text-sm font-bold text-slate-700"><?= $t['university'] ?: 'Open Uni' ?></p>
                        <p class="text-xs font-medium text-slate-400"><?= $t['subject'] ?: 'General Studies' ?></p>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Project Scope</label>
                        <p class="text-sm font-bold text-slate-700"><?= number_format($t['word_count']) ?> Words</p>
                        <p class="text-xs font-medium text-slate-400"><?= $t['country'] ?: 'Global' ?> Region</p>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Submission Date</label>
                        <p class="text-sm font-bold text-slate-700 italic"><?= date("d M, Y", strtotime($t['deadline'])) ?></p>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-emerald-500 uppercase tracking-widest mb-1 block">Final QC Notes</label>
                        <p class="text-xs font-medium text-slate-600 italic">"<?= $t['qc_notes'] ?: 'No notes provided' ?>"</p>
                    </div>
                </div>

                <div class="px-8 py-6 bg-slate-50/50 flex flex-col md:flex-row justify-between items-center gap-6">
                    <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                        <?php $opsDrive = getDriveOperationsLink($t); ?>
                        <?php if(!empty($opsDrive)): ?>
                            <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank"
                               class="drive-btn flex items-center gap-1.5 text-[11px] font-semibold text-zinc-800 bg-zinc-100 hover:bg-zinc-200 px-3 py-2 rounded-lg transition-colors">
                                <span class="material-icons-round text-sm text-emerald-500">folder_shared</span> Operations Files
                            </a>
                        <?php else: ?>
                            <span class="text-xs font-medium text-slate-400 italic">Not synced with Google Drive</span>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-3 text-slate-400">
                        <span class="material-icons-round text-sm">inventory</span>
                        <p class="text-[10px] font-bold uppercase tracking-widest">Archived Securely</p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

</body>
</html>