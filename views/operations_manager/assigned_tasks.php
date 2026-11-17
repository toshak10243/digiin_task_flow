<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
require_once "../../app/services/TaskWorkflowService.php";

date_default_timezone_set('Asia/Kolkata');

 $db = (new Database())->connect();

// Auto-withdraw any tasks assigned for 30+ days without submission
TaskWorkflowService::autoWithdrawStaleTasks($db);

 $team = $db->query("SELECT id,name FROM users WHERE role='operations_team' AND status='active'")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assigned_to'])) {
    $taskId = $_POST['task_id'];
    
    $checkStmt = $db->prepare("SELECT current_stage FROM tasks WHERE id = :id");
    $checkStmt->execute([':id' => $taskId]);
    $taskStatus = $checkStmt->fetch();
    if ($taskStatus && !in_array($taskStatus['current_stage'], ['qc', 'completed'])) {
        
        $stmt = $db->prepare("UPDATE tasks SET 
            assigned_to = :assigned_to, 
            om_notes = :notes,
            assigned_at = NOW() 
            WHERE id = :task_id");

        $stmt->execute([
            ':assigned_to' => $_POST['assigned_to'],
            ':notes'       => $_POST['notes'],
            ':task_id'     => $taskId
        ]);

        header("Location: assigned_tasks.php?updated=1");
    } else {
        // Agar task lock ho chuka hai toh error flag ke saath redirect karein
        header("Location: assigned_tasks.php?error=locked");
    }
    exit;
}

 $updated = isset($_GET['updated']);

 $tasks = $db->query("
    SELECT t.*, u.name AS bde_name, tm.name AS team_member
    FROM tasks t
    JOIN users u ON t.created_by = u.id
    LEFT JOIN users tm ON t.assigned_to = tm.id
    WHERE t.assigned_to IS NOT NULL
    ORDER BY t.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assigned Tasks | Premium Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .task-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid #e2e8f0; }
        .task-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -8px rgba(0,0,0,0.08); border-color: #cbd5e1; }
        .detail-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .detail-value { font-size: 13px; font-weight: 600; color: #334155; }
        .instruction-box { background: #f1f5f9; border-radius: 12px; font-size: 12px; line-height: 1.6; color: #475569; }
        
        /* Smooth Search Input focus */
        .search-input { transition: all 0.2s ease; border: 1.5px solid #e2e8f0; }
        .search-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); outline: none; }

        /* Google Drive button — matches incoming_tasks.php Drive chip */
        .drive-btn { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>

<body>

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="flex justify-between items-end mb-8 max-w-7xl mx-auto">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    Active Tasks 
                    <span class="text-sm font-medium bg-indigo-100 text-indigo-600 px-3 py-1 rounded-full"><?= count($tasks) ?> Total</span>
                </h1>
                <p class="text-slate-500 mt-1">Reviewing current workflow and team assignments.</p>
            </div>
            <div class="text-right hidden sm:block">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Last Updated</p>
                <p class="text-sm font-semibold text-slate-700"><?= date("h:i A") ?></p>
            </div>
        </div>

        <div class="max-w-7xl mx-auto mb-8">
            <div class="relative group">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <span class="material-icons-round text-slate-400 group-focus-within:text-indigo-600 transition-colors">search</span>
                </div>
                <input type="text" id="taskSearch" 
                       placeholder="Search by Task Key, Task Name or Expert..." 
                       class="search-input w-full lg:w-1/2 pl-12 pr-4 py-4 bg-white rounded-2xl shadow-sm font-medium text-slate-700">
            </div>
        </div>

        <div class="max-w-7xl mx-auto space-y-6" id="taskContainer">
            <?php foreach($tasks as $t): ?>
            <div class="task-card bg-white rounded-[2rem] overflow-hidden search-card" 
                 data-search-text="<?= strtolower(htmlspecialchars($t['task_key'] . ' ' . $t['task_name'] . ' ' . $t['team_member'])) ?>">
                
                <div class="px-8 py-5 border-b border-slate-50 flex flex-wrap justify-between items-center gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-slate-900 text-white rounded-2xl flex items-center justify-center font-bold shadow-lg">
                            #<?= $t['id'] ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-bold text-slate-800 tracking-tight uppercase"><?= htmlspecialchars($t['task_key']) ?></h2>
                                <?php if($t['urgent']=='yes'): ?>
                                    <span class="bg-rose-100 text-rose-600 text-[10px] font-black px-2 py-0.5 rounded-md flex items-center gap-1 animate-pulse">
                                        <span class="material-icons text-[12px]">bolt</span> URGENT
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-slate-500 font-medium"><?= htmlspecialchars($t['task_name']) ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <?php 
                            $statusMap = [
                                'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                                'rework' => 'bg-rose-50 text-rose-700 border-rose-100',
                                'assigned' => 'bg-blue-50 text-blue-700 border-blue-100',
                                'in_progress' => 'bg-amber-50 text-amber-700 border-amber-100'
                            ];
                            $colorClass = $statusMap[$t['status']] ?? 'bg-slate-50 text-slate-600';
                        ?>
                        <div class="px-4 py-1.5 rounded-xl border text-[11px] font-bold uppercase tracking-wider <?= $colorClass ?>">
                            <?= str_replace('_', ' ', $t['status']) ?>
                        </div>
                    </div>
                </div>

<div class="px-8 py-6 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-6 bg-white">
    <!-- 1. Expert Name -->
    <div class="flex flex-col">
        <span class="detail-label">Expert Name</span>
        <span class="detail-value flex items-center gap-1">
            <span class="material-icons-round text-sm text-indigo-500">face</span> 
            <?= $t['team_member'] ?: '—' ?>
        </span>
    </div>

    <!-- 2. ✨ Client Name (NAYA) -->
    <div class="flex flex-col border-l border-slate-100 pl-4">
        <span class="detail-label text-emerald-600 italic">Client Name</span>
        <span class="detail-value flex items-center gap-1">
            <span class="material-icons-round text-xs text-emerald-500">person</span>
            <?= htmlspecialchars($t['client_name'] ?: '—') ?>
        </span>
    </div>

    <!-- 3. Target Deadline -->
    <div class="flex flex-col border-l border-slate-100 pl-4">
        <span class="detail-label text-rose-500">Target Deadline</span>
        <span class="detail-value text-rose-600"><?= date("d M, Y | h:i A", strtotime($t['deadline'])) ?></span>
    </div>

    <!-- 4. University -->
    <div class="flex flex-col border-l border-slate-100 pl-4">
        <span class="detail-label">University</span>
        <span class="detail-value truncate" title="<?= $t['university'] ?>"><?= $t['university'] ?: '—' ?></span>
    </div>

    <!-- 5. Subject Area -->
    <div class="flex flex-col border-l border-slate-100 pl-4">
        <span class="detail-label">Subject Area</span>
        <span class="detail-value"><?= $t['subject'] ?: '—' ?></span>
    </div>

    <!-- 6. Word Count -->
    <div class="flex flex-col border-l border-slate-100 pl-4">
        <span class="detail-label">Word Count</span>
        <span class="detail-value"><?= number_format($t['word_count']) ?> Words</span>
    </div>

    <!-- 7. Country -->
    <div class="flex flex-col border-l border-slate-100 pl-4">
        <span class="detail-label">Country</span>
        <span class="detail-value"><?= $t['country'] ?: 'Global' ?></span>
    </div>
</div>

                <div class="px-8 pb-6 grid md:grid-cols-2 gap-6 bg-white">
                    <?php if(!empty($t['instructions'])): ?>
                    <div class="flex flex-col gap-2">
                        <span class="detail-label">Client Instructions</span>
                        <div class="instruction-box p-4 border border-slate-100 italic">
                            <?= nl2br(htmlspecialchars($t['instructions'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="flex flex-col gap-2">
                        <span class="detail-label">Assets & Materials</span>
                        <div class="flex items-center gap-3">
                            <?php $clientDrive = getDriveClientLink($t); ?>
                            <?php if(!empty($clientDrive)): ?>
                                <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank"
                                   class="drive-btn flex items-center gap-1.5 text-[11px] font-semibold text-zinc-800 bg-zinc-100 hover:bg-zinc-200 px-3 py-2 rounded-md transition-colors" title="Open Client Files in Drive">
                                    <span class="material-icons-round text-sm text-blue-500">folder_open</span> Client Files
                                </a>
                            <?php endif; ?>
                            <?php if(!empty($t['file_path']) && file_exists('../../' . $t['file_path'])): ?>
                                <a href="../../<?= htmlspecialchars($t['file_path']) ?>" download title="Download Client Files"
                                   class="flex items-center gap-1.5 text-[11px] font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 px-3 py-2 rounded-md transition-colors">
                                    <span class="material-icons-round text-sm text-indigo-600">download</span> Local Zip
                                </a>
                            <?php endif; ?>
                            <?php if(empty($t['drive_folder_link']) && (empty($t['file_path']) || !file_exists('../../' . $t['file_path']))): ?>
                                <span class="text-xs font-medium text-zinc-400 italic">No files attached</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if(!empty($t['team_notes'])): ?>
                    <div class="md:col-span-2 flex flex-col gap-2">
                        <span class="detail-label text-amber-600 flex items-center gap-1">
                            <span class="material-icons-round text-xs">edit_note</span> Expert Submission Notes (Key Notes)
                        </span>
                        <div class="p-4 rounded-xl bg-amber-50/70 border border-amber-200/60 text-xs font-semibold text-slate-800 leading-relaxed whitespace-pre-wrap">
                            <?= htmlspecialchars($t['team_notes']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

<!-- Footer section jahan dropdown aur notes hain -->
<div class="px-8 py-6 bg-slate-50/80 border-t border-slate-100">
    <?php 
    // Data Integrity ke liye QC aur Completed stages par lock lagaya hai
    $lockedStages = ['qc', 'completed']; 
    if(!in_array($t['current_stage'], $lockedStages)): 
    ?>
        <!-- Re-assign/Edit Form: Sirf tab tak dikhega jab tak kaam Team ke paas hai -->
        <form method="POST" class="flex flex-col lg:flex-row gap-4 items-end">
            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
            
            <div class="w-full lg:w-1/4">
                <label class="block text-[10px] font-bold text-slate-400 mb-2 uppercase tracking-widest">Switch Expert</label>
                <div class="relative">
                    <select name="assigned_to" class="w-full bg-white border border-slate-200 px-4 py-3 rounded-xl text-sm font-bold text-slate-700 focus:ring-4 focus:ring-indigo-100 outline-none appearance-none cursor-pointer">
                        <?php foreach($team as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= ($t['assigned_to']==$m['id'])?'selected':'' ?>><?= $m['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="material-icons-round absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">swap_horiz</span>
                </div>
            </div>

            <div class="w-full lg:flex-1">
                <label class="block text-[10px] font-bold text-slate-400 mb-2 uppercase tracking-widest">Manager Notes</label>
                <input type="text" name="notes" value="<?= htmlspecialchars($t['om_notes'] ?? '') ?>" 
                       class="w-full bg-white border border-slate-200 px-4 py-3 rounded-xl text-sm focus:ring-4 focus:ring-indigo-100 outline-none font-medium">
            </div>

            <button class="w-full lg:w-auto bg-indigo-600 text-white px-8 py-3 rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 hover:bg-indigo-700 hover:-translate-y-1 transition-all flex items-center justify-center gap-2">
                <span class="material-icons-round text-sm">save</span> Update Task
            </button>

            <button type="button" 
                    onclick="confirmWithdrawAssigned(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['task_key'])) ?>', '<?= htmlspecialchars(addslashes($t['team_member'] ?? 'Expert')) ?>')"
                    class="w-full lg:w-auto bg-rose-50 text-rose-600 border border-rose-200 px-6 py-3 rounded-xl font-bold text-sm hover:bg-rose-600 hover:text-white transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                <span class="material-icons-round text-sm">person_remove</span> Withdraw Task
            </button>
        </form>
    <?php else: ?>
        <!-- Enterprise Lock View: Jab kaam QC ya Completed stage mein ho -->
        <div class="flex items-center justify-between bg-amber-50/50 p-4 rounded-2xl border border-amber-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center shadow-sm">
                    <span class="material-icons-round text-xl">lock</span>
                </div>
                <div>
                    <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest leading-none">Assignment Frozen</p>
                    <p class="text-xs text-amber-700 font-medium mt-1.5">
                        The expert has already submitted this work. Changes are disabled to maintain audit integrity.
                    </p>
                </div>
            </div>
            <div class="flex flex-col items-end gap-1">
                <span class="text-[10px] font-bold text-amber-600 bg-white px-3 py-1 rounded-lg shadow-sm border border-amber-100 uppercase">Stage: <?= strtoupper($t['current_stage']) ?></span>
                <span class="text-[9px] font-bold text-slate-400 italic">READ-ONLY MODE</span>
            </div>
        </div>
    <?php endif; ?>
</div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<script>
document.getElementById('taskSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const cards = document.querySelectorAll('.search-card');
    
    cards.forEach(card => {
        const text = card.getAttribute('data-search-text');
        if(text.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
});
function confirmWithdrawAssigned(taskId, taskKey, expertName) {
    Swal.fire({
        title: 'Withdraw Task?',
        html: `Are you sure you want to withdraw <b>${taskKey}</b> from <b>${expertName}</b>?<br><span class="text-xs text-slate-500 mt-2 block">The task will be unassigned and returned to Incoming Tasks. Both you and the expert will be notified.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Withdraw Task',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2rem]' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `withdraw_task_action.php?id=${taskId}`;
        }
    });
}
</script>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'withdrawn'): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Task Withdrawn!',
        text: 'The task has been unassigned and returned to Incoming Tasks queue.',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        background: '#ffffff',
        iconColor: '#e11d48'
    });
    if (window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
</script>
<?php endif; ?>

<?php if($updated): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Successfully Updated',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 2500,
        background: '#ffffff',
        iconColor: '#4f46e5'
    });
</script>
<?php endif; ?>

<?php if(isset($_GET['error']) && $_GET['error'] == 'locked'): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Action Denied',
        text: 'This task is completed and locked for edits.',
        confirmButtonColor: '#ef4444'
    });
</script>
<?php endif; ?>

</body>
</html>