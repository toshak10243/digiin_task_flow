<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');
require_once "../../app/config/database.php";
require_once "../../app/services/TaskWorkflowService.php";

$db = (new Database())->connect();
date_default_timezone_set('Asia/Kolkata');

// Auto-withdraw any tasks assigned for 30+ days without submission
TaskWorkflowService::autoWithdrawStaleTasks($db);

// Filters Catch Karo
$search = $_GET['search'] ?? '';
$filterDate = $_GET['filter_date'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Query Build Karo
$query = "SELECT t.*, expert.name AS expert_name 
          FROM tasks t 
          LEFT JOIN users expert ON t.assigned_to = expert.id 
          WHERE 1=1";

if ($search) {
    $query .= " AND (t.task_key LIKE :search OR t.task_name LIKE :search2 OR t.university LIKE :search3)";
}
if ($filterDate) {
    $query .= " AND DATE(t.deadline) = :fdate";
}
if ($statusFilter && $statusFilter !== 'all') {
    $query .= " AND t.status = :status";
}

$query .= " ORDER BY t.id DESC";
$stmt = $db->prepare($query);

// Bind Parameters
if ($search) {
    $stmt->bindValue(':search', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
    $stmt->bindValue(':search3', "%$search%");
}
if ($filterDate) $stmt->bindValue(':fdate', $filterDate);
if ($statusFilter && $statusFilter !== 'all') $stmt->bindValue(':status', $statusFilter);

$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status Counts
$stats = $db->query("SELECT status, COUNT(*) as count FROM tasks GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalGlobal = array_sum($stats);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Task Inventory | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .tab-btn { transition: all 0.2s ease; border-bottom: 2px solid transparent; }
        .tab-btn.active { border-bottom-color: #4f46e5; color: #4f46e5; background: #f5f3ff; }
    </style>
</head>
<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-8">
        <!-- HEADER -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">Global Inventory</h1>
                <p class="text-sm text-slate-500 font-medium mt-0.5">Manage and track all assignments across the system.</p>
            </div>
            <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm">
                <span class="text-[11px] font-black uppercase text-slate-400">Database Size:</span>
                <span class="text-sm font-extrabold text-indigo-600"><?= $totalGlobal ?> Tasks</span>
            </div>
        </div>

        <!-- CLEAN STATUS TABS -->
        <div class="flex flex-wrap items-center gap-2 mb-8 bg-white p-1.5 rounded-2xl border border-slate-100 shadow-sm inline-flex">
            <a href="?status=all" class="tab-btn px-5 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2 <?= ($statusFilter == 'all' || $statusFilter == '') ? 'active' : 'text-slate-500 hover:bg-slate-50' ?>">
                All <span class="bg-slate-100 px-2 py-0.5 rounded-md text-[10px]"><?= $totalGlobal ?></span>
            </a>
            <?php 
            $statusLabels = [
                'assigned' => ['label' => 'Incoming', 'color' => 'blue'],
                'in_progress' => ['label' => 'Progress', 'color' => 'indigo'],
                'completed' => ['label' => 'QC', 'color' => 'amber'],
                'rework' => ['label' => 'Rework', 'color' => 'rose'],
            ];
            foreach($statusLabels as $status => $data): 
                $count = $stats[$status] ?? 0;
            ?>
            <a href="?status=<?= $status ?>" class="tab-btn px-5 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2 <?= ($statusFilter == $status) ? 'active' : 'text-slate-500 hover:bg-slate-50' ?>">
                <?= $data['label'] ?> <span class="bg-slate-100 px-2 py-0.5 rounded-md text-[10px]"><?= $count ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- FILTERS -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <form action="" method="GET" class="md:col-span-4 flex flex-wrap gap-3">
                <input type="hidden" name="status" value="<?= $statusFilter ?>">
                <div class="relative flex-1 min-w-[300px]">
                    <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Key, Name, or University..." 
                           class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-indigo-100 transition-all">
                </div>
                <input type="date" name="filter_date" value="<?= $filterDate ?>" 
                       class="bg-white border border-slate-200 px-4 py-3 rounded-xl text-sm font-bold text-slate-600 outline-none">
                
                <button type="submit" class="bg-indigo-600 text-white px-8 py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-indigo-700 transition-all shadow-md">Apply</button>
                <a href="all_tasks.php" class="bg-white p-3 rounded-xl text-slate-400 border border-slate-200 hover:text-rose-600 transition-all"><span class="material-icons-round text-sm">refresh</span></a>
            </form>
        </div>

        <!-- TABLE -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="p-5 border-b">Task Detail</th>
                            <th class="p-5 border-b">Academic Brief</th>
                            <th class="p-5 border-b text-center">Status</th>
                            <th class="p-5 border-b">Expert</th>
                            <th class="p-5 border-b">Deadline</th>
                            <th class="p-5 border-b text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($tasks as $t): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="p-5">
                                <p class="text-indigo-600 font-black text-[10px] italic mb-0.5"><?= $t['task_key'] ?></p>
                                <p class="text-[13px] font-bold text-slate-700 leading-tight"><?= htmlspecialchars($t['task_name']) ?></p>
                                <!-- ✨ CLIENT NAME ADDED -->
    <p class="text-[11px] font-bold text-emerald-600 mt-1 flex items-center gap-1">
        <span class="material-icons-round text-[12px]">person</span>
        <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?>
    </p>
                            </td>
                            <td class="p-5">
                                <p class="text-[11px] font-bold text-slate-600 truncate max-w-[200px]">
                                    <?= $t['university'] ?: 'Global' ?> | <span class="text-slate-400"><?= $t['subject'] ?: 'N/A' ?></span>
                                </p>
                                <p class="text-[9px] font-black text-indigo-500 uppercase mt-0.5"><?= $t['country'] ?: 'N/A' ?></p>
                            </td>
                            <td class="p-5 text-center">
                                <?php 
                                    $col = ['assigned'=>'blue', 'in_progress'=>'indigo', 'completed'=>'emerald', 'rework'=>'rose'][$t['status']] ?? 'slate';
                                ?>
                                <span class="px-3 py-1 bg-<?= $col ?>-50 text-<?= $col ?>-600 rounded-lg text-[9px] font-black uppercase border border-<?= $col ?>-100">
                                    <?= str_replace('_', ' ', $t['status']) ?>
                                </span>
                            </td>
                            <td class="p-5">
                                <?php if(!empty($t['assigned_to']) && !empty($t['expert_name'])): ?>
                                    <div class="flex flex-col items-start gap-1.5">
                                        <p class="text-[12px] font-bold text-slate-800 flex items-center gap-1">
                                            <span class="material-icons-round text-xs text-indigo-500">person</span>
                                            <?= htmlspecialchars($t['expert_name']) ?>
                                        </p>
                                        <?php if($t['status'] !== 'completed' && ($t['current_stage'] ?? '') !== 'completed'): ?>
                                        <!-- Tick/Withdraw Action Button -->
                                        <button type="button" 
                                                onclick="confirmWithdrawTask(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['task_key'])) ?>', '<?= htmlspecialchars(addslashes($t['expert_name'])) ?>')"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white border border-rose-200/80 transition-all text-[10px] font-extrabold uppercase tracking-tight shadow-xs cursor-pointer group"
                                                title="Withdraw task from <?= htmlspecialchars($t['expert_name']) ?>">
                                            <span class="material-icons-round text-[13px] text-rose-500 group-hover:text-white">check_box</span>
                                            <span>Withdraw from <?= htmlspecialchars($t['expert_name']) ?></span>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest italic bg-slate-100 px-2.5 py-1 rounded-lg">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-5">
                                <p class="text-[11px] font-bold text-slate-700"><?= date("d M, h:i A", strtotime($t['deadline'])) ?></p>
                            </td>
                            <td class="p-5 text-right">
                                <?php if($t['status'] == 'completed'): ?>
                                    <span class="material-icons-round text-emerald-500 text-sm">verified</span>
                                <?php else: ?>
                                    <?php 
                                        $target = 'incoming_tasks.php';
                                        if(in_array($t['status'], ['assigned', 'in_progress'])) $target = 'assigned_tasks.php';
                                        if($t['status'] == 'rework') $target = 'rework_tasks.php';
                                    ?>
                                    <a href="<?= $target ?>?id=<?= $t['id'] ?>" class="text-[10px] font-black text-indigo-600 hover:underline uppercase tracking-tighter">Manage</a>
                                <?php endif; ?>
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
function confirmWithdrawTask(taskId, taskKey, expertName) {
    Swal.fire({
        title: 'Withdraw Task?',
        html: `Are you sure you want to withdraw <b>${taskKey}</b> from <b>${expertName}</b>?<br><span class="text-xs text-slate-500 mt-2 block">The task will be unassigned and returned to Incoming Tasks. Notifications will be sent to both you and the team member.</span>`,
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

</body>
</html>