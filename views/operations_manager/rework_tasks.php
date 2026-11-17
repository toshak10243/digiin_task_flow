<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

$tasks = $db->query("SELECT t.*, 
                            u.username AS bde_user,
                            tm.username AS team_user
                     FROM tasks t
                     JOIN users u ON t.created_by=u.id
                     LEFT JOIN users tm ON t.assigned_to=tm.id
                     WHERE t.status='rework'
                     ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rework Monitoring | Operations Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #fcfcfd; }
        .rework-card { 
            background: white; 
            border: 1px solid #fee2e2; 
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .rework-card:hover { 
            box-shadow: 0 20px 25px -5px rgba(220, 38, 38, 0.08); 
            border-color: #fca5a5;
        }
        .feedback-gradient {
            background: linear-gradient(135deg, #fff5f5 0%, #fff1f2 100%);
        }

        /* Google Drive button — same style used in incoming_tasks.php /
           completed_tasks.php / assigned_tasks.php / additional_repository.php
           (Inter font, zinc chip). */
        .drive-btn { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 max-w-7xl mx-auto gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <div class="p-3 bg-rose-600 rounded-2xl shadow-lg shadow-rose-200">
                        <span class="material-icons-round text-white">sync_problem</span>
                    </div>
                    Rework Monitoring
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Critical tracking for tasks requiring quality adjustments.</p>
            </div>
            
            <div class="bg-rose-50 border border-rose-100 px-6 py-3 rounded-2xl flex items-center gap-3">
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-rose-400 uppercase tracking-widest leading-none">High Alert</span>
                    <span class="text-sm font-bold text-rose-600"><?= count($tasks) ?> Active Reworks</span>
                </div>
            </div>
        </div>

        <?php if(count($tasks)==0): ?>
        <div class="max-w-7xl mx-auto bg-white border-2 border-dashed border-slate-200 rounded-[2.5rem] p-20 text-center">
            <div class="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="material-icons-round text-emerald-500 text-4xl">verified</span>
            </div>
            <h2 class="text-2xl font-bold text-slate-800">Quality Standard Met!</h2>
            <p class="text-slate-500 mt-1 font-medium">No tasks are currently under rework status.</p>
        </div>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto space-y-8">
            <?php foreach($tasks as $t): ?>
            <div class="rework-card rounded-[2.5rem] overflow-hidden">
                
                <div class="px-8 py-6 border-b border-slate-50 flex flex-wrap justify-between items-center gap-4">
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 bg-rose-50 rounded-2xl flex items-center justify-center border border-rose-100">
                            <span class="text-rose-600 font-black text-lg italic">#<?= substr($t['task_key'], -3) ?></span>
                        </div>
<div>
    <div class="flex items-center gap-2">
        <h2 class="text-xl font-extrabold text-slate-800 tracking-tight"><?= htmlspecialchars($t['task_key']) ?></h2>
        <span class="bg-rose-600 text-white text-[10px] font-black px-2 py-1 rounded-lg uppercase tracking-tighter">Rework Mode</span>
    </div>
    <!-- Project Name aur Client Name combine kar diya -->
    <p class="text-sm text-slate-500 font-semibold uppercase tracking-wide mt-1">
        Project: <?= htmlspecialchars($t['task_name']) ?> 
        <span class="mx-2 text-slate-300">|</span> 
        <span class="text-emerald-600 italic">Client: <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?></span>
    </p>
</div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="text-right border-r pr-4 border-slate-200 hidden sm:block">
                            <p class="text-[10px] font-bold text-slate-400 uppercase">Expert Assigned</p>
                            <p class="text-sm font-bold text-indigo-600"><?= $t['team_user'] ?? 'Unassigned' ?></p>
                        </div>
                        <?php if($t['urgent']=='yes'): ?>
                        <div class="bg-rose-100 text-rose-700 px-4 py-2 rounded-xl text-xs font-black uppercase flex items-center gap-2 animate-pulse">
                            <span class="material-icons text-sm">priority_high</span> Urgent
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-8 grid grid-cols-2 md:grid-cols-4 gap-8 bg-white">
<div class="space-y-1">
        <label class="text-[10px] font-bold text-emerald-500 uppercase tracking-widest">Target Client</label>
        <p class="text-sm font-bold text-slate-700 flex items-center gap-2">
            <span class="material-icons-round text-emerald-500 text-sm">person</span>
            <?= htmlspecialchars($t['client_name'] ?: '—') ?>
        </p>
    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Hard Deadline</label>
                        <p class="text-sm font-bold text-rose-600 flex items-center gap-1">
                            <span class="material-icons-round text-sm">event_busy</span>
                            <?= date("d M Y, h:i A", strtotime($t['deadline'])) ?>
                        </p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">University / Subject</label>
                        <p class="text-sm font-bold text-slate-700 truncate"><?= $t['university'] ?: 'Global' ?> <span class="text-slate-300 mx-1">/</span> <?= $t['subject'] ?: 'General' ?></p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Specs</label>
                        <p class="text-sm font-bold text-slate-700"><?= number_format($t['word_count']) ?> Words <span class="text-slate-400 font-normal ml-1">(<?= $t['country'] ?: 'Any' ?>)</span></p>
                    </div>
                </div>

                <div class="px-8 py-6 feedback-gradient border-y border-rose-100">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-icons-round text-rose-500">campaign</span>
                        <h4 class="text-xs font-black text-rose-900 uppercase tracking-widest">Quality Control Feedback (Why Rework?)</h4>
                    </div>
                    <div class="bg-white/80 border border-rose-200 p-5 rounded-2xl text-sm font-medium text-rose-900 leading-relaxed shadow-sm italic">
                        "<?= nl2br(htmlspecialchars($t['qc_notes'] ?: 'No specific QC notes provided.')) ?>"
                    </div>
                </div>

                <div class="p-8 grid md:grid-cols-2 gap-8 bg-white border-b border-slate-50">
                    <div>
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Resources</h4>
                        <div class="flex flex-wrap gap-3">
                            <?php 
                            $clientDrive = getDriveClientLink($t);
                            $opsDrive    = getDriveOperationsLink($t);
                            ?>
                            <?php if(!empty($clientDrive) || !empty($opsDrive)): ?>
                                <?php if(!empty($clientDrive)): ?>
                                    <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank"
                                       class="drive-btn flex items-center gap-1.5 text-[11px] font-semibold text-zinc-800 bg-zinc-100 hover:bg-zinc-200 px-3 py-2 rounded-lg transition-colors">
                                        <span class="material-icons-round text-sm text-blue-500">folder_open</span> Client Files
                                    </a>
                                <?php endif; ?>
                                <?php if(!empty($opsDrive)): ?>
                                    <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank"
                                       class="drive-btn flex items-center gap-1.5 text-[11px] font-semibold text-zinc-800 bg-zinc-100 hover:bg-zinc-200 px-3 py-2 rounded-lg transition-colors">
                                        <span class="material-icons-round text-sm text-emerald-500">folder_shared</span> Operations Files
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-xs font-medium text-slate-400 italic">Not synced with Google Drive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Core Instructions</h4>
                        <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 text-xs font-medium text-slate-600 max-h-32 overflow-y-auto custom-scrollbar">
                            <?= nl2br(htmlspecialchars($t['instructions'])) ?>
                        </div>
                    </div>
                </div>

                <div class="px-8 py-4 bg-slate-50/50 flex justify-between items-center">
                    <span class="text-[10px] font-bold text-slate-400 uppercase italic">Created At: <?= date("d M Y", strtotime($t['task_created_at'])) ?></span>
                    <div class="flex gap-2">
                         <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                         <span class="text-[10px] font-bold text-rose-500 uppercase tracking-tighter">Under Revision</span>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

</body>
</html>