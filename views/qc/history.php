<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole(['qc','operations_manager']);

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

/* FILTERS - Securely handled */
$where = "WHERE status IN ('completed','rework')";
$params = [];

if(!empty($_GET['search'])){
    $search = $_GET['search'];
    $where .= " AND task_key LIKE :search";
    $params[':search'] = "%$search%";
}

if(isset($_GET['status']) && $_GET['status']!=''){
    $where .= " AND status = :status";
    $params[':status'] = $_GET['status'];
}

$stmt = $db->prepare("SELECT * FROM tasks $where ORDER BY id DESC");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* STATS */
$total = count($tasks);
$completed = count(array_filter($tasks, fn($t)=>$t['status']=='completed'));
$rework = count(array_filter($tasks, fn($t)=>$t['status']=='rework'));
$totalWords = array_sum(array_column($tasks,'word_count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Review History | Precision Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .data-table-container { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1.5rem; overflow: hidden; }
        .filter-input { transition: all 0.2s ease; border: 1px solid #e2e8f0; }
        .filter-input:focus { border-color: #6366f1; ring: 4px; ring-color: rgba(99, 102, 241, 0.1); outline: none; }
        .row-hover:hover { background-color: #fcfdfe; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="max-w-7xl mx-auto mb-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                <div>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <div class="p-2.5 bg-slate-900 rounded-2xl shadow-lg">
                            <span class="material-icons-round text-white">history_toggle_off</span>
                        </div>
                        QC Review History
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium italic">Comprehensive audit trail of all quality assessments.</p>
                </div>
                
                <form class="bg-white p-2 rounded-2xl shadow-sm border border-slate-200 flex flex-wrap md:flex-nowrap gap-2 items-center w-full md:w-auto">
                    <div class="relative flex-1 md:w-60">
                        <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search ID..." 
                               class="filter-input w-full pl-10 pr-4 py-2 rounded-xl text-sm font-medium">
                    </div>

                    <select name="status" class="filter-input px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 bg-slate-50">
                        <option value="">All Status</option>
                        <option value="completed" <?= isset($_GET['status']) && $_GET['status']=='completed' ? 'selected':'' ?>>Approved</option>
                        <option value="rework" <?= isset($_GET['status']) && $_GET['status']=='rework' ? 'selected':'' ?>>Rework</option>
                    </select>

                    <button class="bg-indigo-600 text-white px-6 py-2 rounded-xl font-bold text-sm hover:bg-indigo-700 transition-all flex items-center gap-2">
                        Filter <span class="material-icons-round text-xs">tune</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="max-w-7xl mx-auto grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Audited</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= $total ?></p>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm border-l-4 border-l-emerald-500">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Approved</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= $completed ?></p>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm border-l-4 border-l-rose-500">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Reworks</p>
                <p class="text-2xl font-black text-rose-600 mt-1"><?= $rework ?></p>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm bg-slate-900">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Volume Reviewed</p>
                <p class="text-2xl font-black text-blue-400 mt-1"><?= number_format($totalWords) ?> <span class="text-xs font-normal text-slate-400">Words</span></p>
            </div>
        </div>

        <div class="max-w-7xl mx-auto data-table-container shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100 text-[11px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="px-6 py-5">Task Identity</th>
                            <th class="px-6 py-5">Word Count</th>
                            <th class="px-6 py-5 text-center">Google Drive</th>
                            <th class="px-6 py-5">Auditor Notes</th>
                            <th class="px-6 py-5 text-center">Outcome</th>
                            <th class="px-6 py-5 text-right">Audit Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($tasks as $t): ?>
                        <tr class="row-hover transition-colors">
                            <td class="px-6 py-5">
                                <span class="text-sm font-extrabold text-slate-800 block leading-none"><?= htmlspecialchars($t['task_key']) ?></span>
                                <span class="text-[10px] font-bold text-slate-400 uppercase mt-1 inline-block tracking-tighter">Stage: <?= htmlspecialchars($t['current_stage']) ?></span>
                            </td>
                            
                            <td class="px-6 py-5">
                                <span class="text-sm font-bold text-slate-600"><?= number_format($t['word_count']) ?></span>
                                <span class="text-[10px] text-slate-400 ml-1">wds</span>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <?php 
                                $clientDrive = getDriveClientLink($t);
                                $opsDrive    = getDriveOperationsLink($t);
                                ?>
                                <?php if(!empty($clientDrive) || !empty($opsDrive)): ?>
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- GOOGLE DRIVE - CLIENT FILES -->
                                        <?php if(!empty($clientDrive)): ?>
                                            <a href="<?= htmlspecialchars($clientDrive) ?>" 
                                               target="_blank" 
                                               title="Open Client Files in Drive" 
                                               class="h-8 w-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center hover:bg-blue-600 hover:text-white transition-all shadow-sm border border-blue-100">
                                                <span class="material-icons-round text-sm">folder_open</span>
                                            </a>
                                        <?php endif; ?>

                                        <!-- GOOGLE DRIVE - OPERATIONS FILES -->
                                        <?php if(!empty($opsDrive)): ?>
                                            <a href="<?= htmlspecialchars($opsDrive) ?>" 
                                               target="_blank" 
                                               title="Open Operations Files in Drive" 
                                               class="h-8 w-8 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center hover:bg-emerald-600 hover:text-white transition-all shadow-sm border border-emerald-100">
                                                <span class="material-icons-round text-sm">folder_shared</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[10px] font-bold text-slate-300 uppercase tracking-widest">Not synced with Google Drive</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-6 py-5">
                                <p class="text-xs text-slate-500 italic max-w-xs truncate" title="<?= htmlspecialchars($t['qc_notes']) ?>">
                                    <?= $t['qc_notes'] ? htmlspecialchars($t['qc_notes']) : '<span class="text-slate-300">No notes provided</span>' ?>
                                </p>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <?php if($t['status']=='completed'): ?>
                                    <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                        <span class="px-3 py-1 bg-emerald-50 text-emerald-600 text-[10px] font-black rounded-lg uppercase tracking-widest border border-emerald-100">Approved</span>
                                        <button type="button" onclick="confirmRevert(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key']) ?>')"
                                                class="px-2 py-1 bg-amber-50 text-amber-700 hover:bg-amber-600 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-wider border border-amber-200 transition shadow-sm"
                                                title="Recall/revert deployment back to QC review queue">
                                            <span class="material-icons-round text-xs inline-block align-middle">undo</span> Recall
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="px-3 py-1 bg-rose-50 text-rose-600 text-[10px] font-black rounded-lg uppercase tracking-widest border border-rose-100">Rework</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-6 py-5 text-right">
                                <span class="text-xs font-bold text-slate-400"><?= date("d M, Y", strtotime($t['deadline'])) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if(count($tasks) == 0): ?>
                <div class="p-20 text-center">
                    <span class="material-icons-round text-slate-200 text-6xl">find_in_page</span>
                    <p class="text-slate-400 mt-4 font-bold uppercase tracking-widest text-sm">No review history matches your filter</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
function confirmRevert(id, key) {
    Swal.fire({
        title: 'Recall Deployment?',
        text: `Are you sure you want to recall deployment for ${key}? The task will be pulled back from BDE and returned to your QC Pending queue for further review or rework.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Recall Deployment',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2.5rem]' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `revert_task.php?id=${id}`;
        }
    });
}
</script>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'reverted'): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Deployment Recalled!',
        text: 'The task has been pulled back and is now available in your Pending QC Tasks queue.',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-[2.5rem]' }
    });
</script>
<?php endif; ?>

</body>
</html>