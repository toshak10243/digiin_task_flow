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

 $assignedSuccess = false;

// 1. Date filter catch karo
 $filterDate = $_GET['filter_date'] ?? '';

// Search query catch karo
 $searchQuery = trim($_GET['search'] ?? '');

// 2. Team fetch karo (Assignment ke liye)
 $team = $db->query("SELECT id,name FROM users WHERE role='operations_team' AND status='active'")
           ->fetchAll(PDO::FETCH_ASSOC);

// 3. Task Assignment Handle karo
if($_SERVER['REQUEST_METHOD']=='POST'){

    /*
    |--------------------------------------------------------------------------
    | Fetch Task Details Before Assignment
    |--------------------------------------------------------------------------
    */

    $taskInfo = $db->prepare("
        SELECT task_key, urgent
        FROM tasks
        WHERE id = ?
    ");

    $taskInfo->execute([
        $_POST['task_id']
    ]);

    $taskData = $taskInfo->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("UPDATE tasks 
        SET assigned_to=:assigned_to, 
            om_notes=:notes, 
            current_stage='operations_team',
            status='assigned',
            assigned_at=NOW()
        WHERE id=:task_id");
    $stmt->execute([
        ':assigned_to'=>$_POST['assigned_to'],
        ':notes'=>$_POST['notes'],
        ':task_id'=>$_POST['task_id']
    ]);

    /*
    |--------------------------------------------------------------------------
    | Send Notification To Assigned Expert
    |--------------------------------------------------------------------------
    */

    require_once "../../app/services/NotificationService.php";
    NotificationService::onTaskAssigned(
        (int)$_POST['task_id'],
        $taskData['task_key'],
        (int)$_POST['assigned_to'],
        ($taskData['urgent'] === "yes")
    );

    $redirectParams = ['assigned' => 1];
    $postFilterDate = $_POST['filter_date'] ?? $_GET['filter_date'] ?? '';
    $postSearchQuery = trim($_POST['search'] ?? $_GET['search'] ?? '');

    if (!empty($postFilterDate)) {
        $redirectParams['filter_date'] = $postFilterDate;
    }
    if ($postSearchQuery !== '') {
        $redirectParams['search'] = $postSearchQuery;
    }

    header("Location: incoming_tasks.php?" . http_build_query($redirectParams));
    exit;
}

if(isset($_GET['assigned'])) $assignedSuccess = true;

// 4. Query build karo (FILTER LOGIC YAHAN HAI)
 $params = [];

 $query = "SELECT t.*, u.name AS bde_name 
          FROM tasks t 
          JOIN users u ON t.created_by=u.id
          WHERE t.current_stage='operations_manager'";

if($filterDate){
    $start = $filterDate . " 00:00:00";
    $end   = $filterDate . " 23:59:59";
    $query .= " AND t.deadline BETWEEN :start AND :end";
    $params[':start'] = $start;
    $params[':end']   = $end;
}

if($searchQuery !== ''){
    $query .= " AND (
        t.id           LIKE :search OR
        t.task_key     LIKE :search OR
        t.task_name    LIKE :search OR
        t.client_name  LIKE :search OR
        t.subject      LIKE :search OR
        t.university   LIKE :search
    )";
    $params[':search'] = '%' . $searchQuery . '%';
}

 $query .= " ORDER BY t.id DESC";

// 5. Final tasks fetch
 $stmt = $db->prepare($query);
 $stmt->execute($params);
 $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incoming Tasks | Operations Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .drive-btn { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-8 overflow-y-auto custom-scrollbar">

        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-800 flex items-center gap-3">
                    <div class="p-2 bg-indigo-600 rounded-lg shadow-lg shadow-indigo-200">
                        <span class="material-icons-round text-white text-2xl">assignment_returned</span>
                    </div>
                    Incoming Assignments
                </h1>
                <p class="text-slate-500 mt-1 font-medium">Review and delegate incoming workflow to your team.</p>
            </div>

            <div class="flex items-center gap-3 bg-white p-2 px-4 rounded-2xl shadow-sm border border-slate-200">
                <div class="text-right">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400">Current Date</p>
                    <p class="text-sm font-semibold text-slate-700"><?= date("d M, Y â€¢ h:i A"); ?></p>
                </div>
            </div>
        </div>

        <!-- Quick Filter Bar -->
        <div class="bg-white p-3 rounded-2xl shadow-sm border border-slate-100 flex flex-wrap gap-4 items-center mb-8">
            <div class="flex-1 min-w-[200px]">
                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-2">Quick Deadline Filter</p>
            </div>

            <form method="GET" class="flex flex-wrap gap-2 items-center">

                <input type="date" name="filter_date" value="<?= htmlspecialchars($filterDate) ?>"
                    class="bg-slate-50 border border-slate-200 px-4 py-2.5 rounded-xl text-sm font-bold text-slate-600 outline-none focus:border-indigo-500 transition-all">

                <div class="relative">
                    <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                    <input
                        type="text"
                        name="search"
                        value="<?= htmlspecialchars($searchQuery) ?>"
                        placeholder="Search by ID, key, client, subjectâ€¦"
                        class="bg-slate-50 border border-slate-200 pl-9 pr-4 py-2.5 rounded-xl text-sm font-bold text-slate-600 outline-none focus:border-indigo-500 transition-all w-64"
                    >
                </div>

                <button type="submit" class="bg-slate-900 hover:bg-indigo-600 text-white px-6 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2 shadow-lg shadow-slate-200">
                    <span class="material-icons-round text-sm">filter_list</span> Filter
                </button>

                <?php if($filterDate || $searchQuery !== ''): ?>
                    <a href="incoming_tasks.php"
                       class="bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white px-4 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center justify-center border border-rose-100"
                       title="Clear Filter">
                        <span class="material-icons-round text-sm">close</span>
                    </a>
                <?php endif; ?>

            </form>
        </div>

        <?php if(count($tasks) == 0): ?>
            <div class="flex flex-col items-center justify-center h-[60vh] glass-card rounded-[2rem] border-2 border-dashed border-slate-200">
                <div class="w-24 h-24 bg-<?= ($filterDate || $searchQuery !== '') ? 'amber' : 'green' ?>-50 rounded-full flex items-center justify-center mb-4">
                    <span class="material-icons-round text-<?= ($filterDate || $searchQuery !== '') ? 'amber' : 'green' ?>-500 text-5xl">
                        <?= ($filterDate || $searchQuery !== '') ? 'event_busy' : 'verified' ?>
                    </span>
                </div>

                <h2 class="text-xl font-bold text-slate-800">
                    <?= ($filterDate || $searchQuery !== '') ? "No Matches Found" : "Inbox Zero!" ?>
                </h2>

                <p class="text-slate-500 font-medium text-center px-6">
                    <?= ($filterDate || $searchQuery !== '')
                        ? "There are no pending assignments matching your filters." 
                        : "Great job! All incoming tasks have been successfully delegated."; ?>
                </p>

                <?php if($filterDate || $searchQuery !== ''): ?>
                    <a href="incoming_tasks.php" class="mt-4 flex items-center gap-2 text-indigo-600 font-bold text-sm hover:text-indigo-800 transition-colors">
                        <span class="material-icons-round text-sm">refresh</span>
                        Clear filters and view all tasks
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <div class="grid grid-cols-1 gap-6">
                <?php foreach($tasks as $t): ?>
                <div class="glass-card rounded-3xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition-all duration-300 group">

                    <div class="p-6 border-b border-slate-100 flex flex-wrap justify-between items-center gap-4 group-hover:bg-slate-50/50 transition-colors">
                        <div class="flex items-center gap-4">
                            <div class="h-12 w-12 bg-slate-100 rounded-2xl flex items-center justify-center font-bold text-indigo-600 border border-slate-200">
                                #<?= $t['id'] ?>
                            </div>
                            <div>
                                <h3 class="font-bold text-lg text-slate-800 tracking-tight leading-none"><?= htmlspecialchars($t['task_key']) ?></h3>
                                <p class="text-sm text-slate-500 mt-1 font-medium"><?= htmlspecialchars($t['task_name']) ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <?php if($t['urgent']=='yes'): ?>
                            <span class="flex items-center gap-1.5 bg-rose-50 text-rose-600 px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider animate-pulse border border-rose-100">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span> Urgent
                            </span>
                            <?php endif; ?>
                            <?php if(!empty($t['additional_instructions'])): ?>
                            <span class="flex items-center gap-1.5 bg-amber-50 text-amber-900 border border-amber-300 px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider shadow-xs">
                                <span class="material-icons-round text-sm text-amber-600">new_releases</span> Extra Info
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-6 grid md:grid-cols-4 gap-6 bg-white">

                        <div class="md:col-span-4">
                            <label class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1">
                                <span class="material-icons-round text-xs">notes</span> Key Notes / Instructions
                            </label>

                            <div class="mt-2 p-4 rounded-xl bg-indigo-50 border border-indigo-100 text-sm text-slate-700 leading-relaxed max-h-40 overflow-y-auto custom-scrollbar">
                                <?= !empty($t['instructions']) ? nl2br(htmlspecialchars($t['instructions'])) : '<span class="text-slate-400 italic">No instructions provided</span>' ?>
                            </div>

                            <?php if (!empty($t['additional_instructions'])): ?>
                                <div class="mt-3 p-4 rounded-xl bg-amber-50/90 border-2 border-amber-300 text-amber-950 leading-relaxed relative overflow-hidden shadow-xs">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <span class="text-[10px] font-black uppercase tracking-wider text-amber-900 flex items-center gap-1">
                                            <span class="material-icons-round text-sm text-amber-600">new_releases</span>
                                            Additional Information / Client Update
                                        </span>
                                        <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 border border-amber-300">⚡ Updated</span>
                                    </div>
                                    <div class="font-semibold text-xs whitespace-pre-line leading-relaxed">
                                        <?= nl2br(htmlspecialchars($t['additional_instructions'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-1">
                            <label class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1">
                                <span class="material-icons-round text-xs">description</span> Word Count
                            </label>
                            <p class="text-sm font-bold text-slate-700"><?= number_format($t['word_count']) ?> Words</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1">
                                <span class="material-icons-round text-xs">school</span> Subject / Uni / Client
                            </label>

                            <p class="text-sm font-bold text-slate-700 truncate">
                                <?= htmlspecialchars($t['subject'] ?: 'N/A') ?> |
                                <span class="text-slate-500 font-normal"><?= htmlspecialchars($t['university'] ?: 'Global') ?></span>
                            </p>

                            <div class="flex items-center gap-1.5 mt-1">
                                <span class="material-icons-round text-[12px] text-emerald-500">person</span>
                                <span class="text-[11px] font-black text-emerald-600 uppercase tracking-tighter">
                                    Client Name: <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?>
                                </span>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="text-[11px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1 text-rose-500">
                                <span class="material-icons-round text-xs">alarm</span> Deadline
                            </label>
                            <p class="text-sm font-bold text-rose-600"><?= date("d M, h:i A", strtotime($t['deadline'])) ?></p>
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <?php $clientDrive = getDriveClientLink($t); ?>
                            <?php if(!empty($clientDrive)): ?>
                                <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank" title="Open Client Files in Drive"
                                   class="drive-btn flex items-center gap-1.5 text-[11px] font-semibold text-zinc-800 bg-zinc-100 hover:bg-zinc-200 px-3 py-2 rounded-lg transition-colors">
                                    <span class="material-icons-round text-sm text-blue-500">folder_open</span> Client Files
                                </a>
                            <?php endif; ?>
                            <?php if(!empty($t['file_path']) && file_exists('../../' . $t['file_path'])): ?>
                                <a href="../../<?= htmlspecialchars($t['file_path']) ?>" download title="Download Client Files"
                                   class="flex items-center gap-1.5 text-[11px] font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 px-3 py-2 rounded-lg transition-colors">
                                    <span class="material-icons-round text-sm text-indigo-600">download</span> Local Zip
                                </a>
                            <?php endif; ?>
                            <?php if(empty($t['drive_folder_link']) && (empty($t['file_path']) || !file_exists('../../' . $t['file_path']))): ?>
                                <span class="text-xs font-medium text-slate-400 italic">No files attached</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-slate-50 p-6 border-t border-slate-100">
                        <form method="POST" class="flex flex-col lg:flex-row gap-4 items-end">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="filter_date" value="<?= htmlspecialchars($filterDate) ?>">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($searchQuery) ?>">

                            <div class="w-full lg:w-1/3">
                                <label class="block text-xs font-bold text-slate-500 mb-2 uppercase ml-1">Assign Expert</label>
                                <div class="relative">
                                    <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">person_add</span>
                                    <select name="assigned_to" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all text-sm font-medium appearance-none bg-white" required>
                                        <option value="">Choose team member...</option>
                                        <?php foreach($team as $m): ?>
                                        <option value="<?= $m['id'] ?>"><?= $m['name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="w-full lg:flex-1">
                                <label class="block text-xs font-bold text-slate-500 mb-2 uppercase ml-1">Internal Notes (Optional)</label>
                                <input type="text" name="notes" placeholder="Add specific instructions for the team..."
                                       class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all text-sm bg-white">
                            </div>

                            <button class="w-full lg:w-auto bg-indigo-600 text-white px-8 py-3 rounded-xl font-bold text-sm shadow-xl shadow-indigo-100 hover:bg-indigo-700 hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
                                Deploy Task <span class="material-icons-round text-sm">send</span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
    </main>
</div>

<?php if($assignedSuccess): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Task Delegated',
        text: 'Assignment has been moved to the operations queue.',
        confirmButtonColor: '#4f46e5',
        timer: 3000,
        customClass: {
            popup: 'rounded-3xl',
            confirmButton: 'rounded-xl px-6 py-2.5'
        }
    });
</script>
<?php endif; ?>

</body>
</html>