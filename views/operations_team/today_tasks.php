<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

$db = (new Database())->connect();
$userId = $_SESSION['user_id'];

// Selected date
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$dateType = $_GET['type'] ?? 'created';
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday  = ($selectedDate === date('Y-m-d'));

$whereDate = ($dateType === 'deadline') ? "DATE(t.deadline) = :dt" : "(DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)";

$stmt = $db->prepare("
    SELECT t.*, bde.name AS bde_name
    FROM tasks t
    LEFT JOIN users bde ON t.created_by = bde.id
    WHERE t.assigned_to = :user
    AND {$whereDate}
    ORDER BY t.id DESC
");
$stmt->execute([
    ':user' => $userId,
    ':dt'   => $selectedDate
]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalDayTasks = count($tasks);
$dayCompleted  = 0;
$dayInWork     = 0;
$dayQC         = 0;
$dayRework     = 0;
$dayWords      = 0;

foreach ($tasks as $t) {
    $dayWords += (int)($t['word_count'] ?? 0);
    if ($t['current_stage'] === 'qc') {
        $dayQC++;
    } elseif ($t['status'] === 'completed' || $t['current_stage'] === 'completed') {
        $dayCompleted++;
    } elseif ($t['status'] === 'rework') {
        $dayRework++;
    } else {
        $dayInWork++;
    }
}

function getExpertStatusBadge($t) {
    if ($t['current_stage'] === 'qc') {
        return ['label' => 'Under QC Review', 'class' => 'bg-purple-50 text-purple-700 border-purple-200'];
    }
    return match($t['status']) {
        'completed'   => ['label' => 'Completed',   'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'in_progress' => ['label' => 'In Progress', 'class' => 'bg-indigo-50 text-indigo-700 border-indigo-200'],
        'assigned'    => ['label' => 'Assigned',    'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
        'rework'      => ['label' => 'Rework',      'class' => 'bg-rose-50 text-rose-700 border-rose-200'],
        default       => ['label' => str_replace('_', ' ', $t['status']), 'class' => 'bg-slate-100 text-slate-700 border-slate-200']
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Tasks | Expert Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: #f8fafc; }
        .surface {
            background: #fff;
            border: 1px solid #eef0f3;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        .stat-card {
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.08);
        }
    </style>
</head>
<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-5 lg:p-8 max-w-[1440px] mx-auto w-full">

        <!-- Top Header & Date Navigation -->
        <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-5 mb-8">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-amber-500 text-white flex items-center justify-center shadow-lg shadow-amber-200">
                    <span class="material-icons-round text-2xl">calendar_today</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">Today's Tasks Hub</h1>
                        <?php if ($isToday): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider animate-pulse">Today</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 font-medium mt-0.5">
                        Your tasks <?= $dateType === 'deadline' ? 'delivering on' : 'assigned on' ?> <span class="font-bold text-slate-700"><?= date('l, d F Y', strtotime($selectedDate)) ?></span>
                    </p>
                </div>
            </div>

            <!-- Date Controls -->
            <div class="flex flex-wrap items-center gap-2.5 bg-white p-2 rounded-2xl border border-slate-200 shadow-sm">
                <a href="?date=<?= $prevDate ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1">
                    <span class="material-icons-round text-base">chevron_left</span> Prev
                </a>

                <a href="?date=<?= date('Y-m-d') ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold transition-all <?= $isToday ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                    Today
                </a>

                <form method="GET" class="flex items-center gap-2 m-0">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($dateType) ?>">
                    <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" 
                           onchange="this.form.submit()"
                           class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer">
                </form>

                <a href="?date=<?= $nextDate ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1">
                    Next <span class="material-icons-round text-base">chevron_right</span>
                </a>

                <div class="border-l border-slate-200 pl-2 flex items-center gap-1.5">
                    <span class="text-[10px] font-black uppercase text-slate-400 tracking-wider hidden sm:inline">Filter By:</span>
                    <a href="?date=<?= $selectedDate ?>&type=created"
                       class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all <?= $dateType === 'created' ? 'bg-indigo-600 text-white shadow-sm ring-2 ring-indigo-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <span class="material-icons-round text-sm">assignment</span> Assigned Date
                    </a>
                    <a href="?date=<?= $selectedDate ?>&type=deadline"
                       class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all <?= $dateType === 'deadline' ? 'bg-rose-600 text-white shadow-sm ring-2 ring-rose-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <span class="material-icons-round text-sm">event</span> Delivery Deadline
                    </a>
                </div>
            </div>
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 mb-7">
            <div class="stat-card surface p-4 border-l-4 border-l-indigo-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total <?= $dateType === 'deadline' ? 'Deliveries' : 'Assigned' ?></span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $totalDayTasks ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5"><?= $dateType === 'deadline' ? 'Due on this date' : 'Assigned on this date' ?></p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-amber-400">
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-500">In Work</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayInWork ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Active with you</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-purple-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-purple-500">Under QC</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayQC ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Submitted / In review</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-emerald-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-500">Completed</span>
                <h3 class="text-2xl font-extrabold text-emerald-600 mt-1"><?= $dayCompleted ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Approved</p>
            </div>

            <div class="stat-card surface p-4 bg-gradient-to-br from-indigo-900 to-slate-900 text-white border-none shadow-md">
                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-300">Word Count</span>
                <h3 class="text-2xl font-extrabold text-white mt-1"><?= number_format($dayWords) ?></h3>
                <p class="text-[10px] text-indigo-200 font-bold mt-0.5"><?= $dayRework > 0 ? "⚠️ {$dayRework} Rework" : "Total volume" ?></p>
            </div>
        </div>

        <!-- Task List Table -->
        <div class="surface overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-icons-round text-amber-500 text-lg">assignment</span>
                    <h3 class="font-bold text-slate-800 text-sm">
                        <?= $dateType === 'deadline' ? 'Tasks Delivering on ' : 'Tasks Assigned on ' ?><?= date('d M Y', strtotime($selectedDate)) ?>
                    </h3>
                </div>
                <span class="text-xs text-slate-400 font-bold"><?= count($tasks) ?> task<?= count($tasks) === 1 ? '' : 's' ?></span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4">Task Details</th>
                            <th class="px-6 py-4">Words</th>
                            <th class="px-6 py-4">Assigned & Delivery (Deadline)</th>
                            <th class="px-6 py-4 text-center">Google Drive Subfolders</th>
                            <th class="px-6 py-4 text-center">Status / Stage</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs font-medium">
                        <?php if (count($tasks) > 0): ?>
                            <?php foreach ($tasks as $t): 
                                $clientLink = getDriveClientLink($t);
                                $opsLink    = getDriveOperationsLink($t);
                                $badge      = getExpertStatusBadge($t);
                                $canRecall  = ($t['current_stage'] === 'qc');
                                $assignedDt = $t['task_created_at'] ?? $t['created_at'];
                                $isTodayAssigned = (!empty($assignedDt) && date('Y-m-d', strtotime($assignedDt)) === date('Y-m-d'));
                                $isTodayDeadline = (!empty($t['deadline']) && date('Y-m-d', strtotime($t['deadline'])) === date('Y-m-d'));
                            ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-slate-800 text-sm tracking-tight">
                                            <?= htmlspecialchars($t['task_key']) ?>
                                        </span>
                                        <?php if (($t['urgent'] ?? '') === 'yes'): ?>
                                        <span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-black rounded uppercase">Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[11px] text-slate-500 font-semibold truncate max-w-xs mt-0.5">
                                        <?= htmlspecialchars($t['task_name']) ?>
                                    </p>
                                    <span class="text-[10px] text-slate-400">Client: <?= htmlspecialchars($t['client_name'] ?? 'N/A') ?></span>
                                </td>

                                <td class="px-6 py-4 font-bold text-slate-700">
                                    <?= number_format($t['word_count'] ?? 0) ?>
                                </td>

                                <td class="px-6 py-4">
                                    <!-- Assigned Date/Time -->
                                    <div class="flex items-center gap-1.5 mb-1.5">
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold uppercase <?= $isTodayAssigned ? 'bg-emerald-100 text-emerald-800 font-black' : 'bg-slate-100 text-slate-600' ?>">
                                            <?= $isTodayAssigned ? 'Today Assigned' : 'Assigned' ?>
                                        </span>
                                        <span class="text-xs font-semibold text-slate-700">
                                            <?= !empty($assignedDt) ? date('d M, h:i A', strtotime($assignedDt)) : 'N/A' ?>
                                        </span>
                                    </div>

                                    <!-- Delivery Deadline Date/Time -->
                                    <div class="flex items-center gap-1.5">
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold uppercase <?= $isTodayDeadline ? 'bg-rose-100 text-rose-700 font-black animate-pulse' : 'bg-amber-100 text-amber-800' ?>">
                                            <?= $isTodayDeadline ? 'Due Today' : 'Deliver By' ?>
                                        </span>
                                        <span class="text-xs font-bold <?= $isTodayDeadline ? 'text-rose-600' : 'text-slate-800' ?>">
                                            <?= !empty($t['deadline']) ? date('d M, h:i A', strtotime($t['deadline'])) : 'N/A' ?>
                                        </span>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-1.5">
                                        <?php if (!empty($clientLink)): ?>
                                        <a href="<?= htmlspecialchars($clientLink) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Client Files Subfolder">
                                            <span class="material-icons-round text-sm">folder_open</span> Client Files
                                        </a>
                                        <?php endif; ?>

                                        <?php if (!empty($opsLink)): ?>
                                        <a href="<?= htmlspecialchars($opsLink) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Operations Files Subfolder">
                                            <span class="material-icons-round text-sm">inventory_2</span> Operations Files
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 border text-[10px] font-bold rounded-lg uppercase tracking-wider <?= $badge['class'] ?>">
                                        <?= $badge['label'] ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <?php if ($canRecall): ?>
                                        <button onclick="confirmRecall(<?= (int)$t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-600 hover:text-white text-rose-700 rounded-lg text-xs font-bold transition-colors flex items-center gap-1">
                                            <span class="material-icons-round text-sm">undo</span> Recall
                                        </button>
                                        <?php else: ?>
                                        <a href="my_tasks.php" 
                                           class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition-colors">
                                            Open Task
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-300 mb-3">
                                            <span class="material-icons-round text-3xl">event_available</span>
                                        </div>
                                        <h4 class="text-sm font-bold text-slate-700">No tasks assigned for <?= date('d M Y', strtotime($selectedDate)) ?></h4>
                                        <p class="text-xs text-slate-400 mt-1">Check today's active tasks or review another date.</p>
                                        <a href="?date=<?= date('Y-m-d') ?>" class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-colors shadow-sm">
                                            Back to Today
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
function confirmRecall(taskId, taskKey) {
    Swal.fire({
        title: 'Recall Submitted Work?',
        html: `Are you sure you want to recall <strong>${taskKey}</strong> from QC review?<br><br><span class="text-xs text-rose-600">The submitted file will be cleared from Drive & server, and the task will return to your active queue for re-upload.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Recall Work'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `recall_task.php?id=${taskId}`;
        }
    });
}
</script>

</body>
</html>
