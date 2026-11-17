<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

$db = (new Database())->connect();

// Selected date (default: today)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$dateType = $_GET['type'] ?? 'created'; // 'created' or 'deadline'
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday  = ($selectedDate === date('Y-m-d'));

// Query date-wise tasks
$whereDate = ($dateType === 'deadline') ? "DATE(t.deadline) = :dt" : "(DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)";

$stmt = $db->prepare("
    SELECT t.*, 
           expert.name AS expert_name, 
           bde.name AS bde_name
    FROM tasks t
    LEFT JOIN users expert ON t.assigned_to = expert.id
    LEFT JOIN users bde ON t.created_by = bde.id
    WHERE {$whereDate}
    ORDER BY t.id DESC
");
$stmt->execute([':dt' => $selectedDate]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics for the selected date
$totalDayTasks = count($tasks);
$dayCompleted  = 0;
$dayInProgress = 0;
$dayAssigned   = 0;
$dayRework     = 0;
$dayUrgent     = 0;
$dayWords      = 0;

foreach ($tasks as $t) {
    $dayWords += (int)($t['word_count'] ?? 0);
    if (($t['urgent'] ?? '') === 'yes') $dayUrgent++;
    match ($t['status']) {
        'completed', 'approved' => $dayCompleted++,
        'in_progress'           => $dayInProgress++,
        'assigned'              => $dayAssigned++,
        'rework'                => $dayRework++,
        default                 => null
    };
}

function getStatusBadge($status) {
    return match($status) {
        'completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'approved'    => 'bg-purple-50 text-purple-700 border-purple-200',
        'in_progress' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'assigned'    => 'bg-amber-50 text-amber-700 border-amber-200',
        'rework'      => 'bg-rose-50 text-rose-700 border-rose-200',
        'new'         => 'bg-sky-50 text-sky-700 border-sky-200',
        default       => 'bg-slate-100 text-slate-700 border-slate-200'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's & Daily Tasks | Ops Manager</title>
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
        .drive-btn {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .drive-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
    </style>
</head>
<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-5 lg:p-8 max-w-[1440px] mx-auto w-full">

        <!-- ===================== TOP BAR & DATE SELECTOR ===================== -->
        <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-5 mb-8">
            <div>
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shadow-lg shadow-indigo-200">
                        <span class="material-icons-round text-2xl">calendar_today</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">Daily Task Archive</h1>
                            <?php if ($isToday): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider animate-pulse">Today</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 font-medium mt-0.5">
                            Showing tasks for <span class="font-bold text-slate-700"><?= date('l, d F Y', strtotime($selectedDate)) ?></span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Date Navigator Controls -->
            <div class="flex flex-wrap items-center gap-2.5 bg-white p-2 rounded-2xl border border-slate-200 shadow-sm">
                <!-- Previous Day -->
                <a href="?date=<?= $prevDate ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1"
                   title="Previous Day (<?= date('d M', strtotime($prevDate)) ?>)">
                    <span class="material-icons-round text-base">chevron_left</span> Prev
                </a>

                <!-- Quick Today Button -->
                <a href="?date=<?= date('Y-m-d') ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold transition-all <?= $isToday ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                    Today
                </a>

                <!-- Custom Date Input Form -->
                <form method="GET" class="flex items-center gap-2 m-0">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($dateType) ?>">
                    <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" 
                           onchange="this.form.submit()"
                           class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer">
                </form>

                <!-- Next Day -->
                <a href="?date=<?= $nextDate ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1"
                   title="Next Day (<?= date('d M', strtotime($nextDate)) ?>)">
                    Next <span class="material-icons-round text-base">chevron_right</span>
                </a>

                <!-- Type filter toggle -->
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

        <!-- ===================== DATE KPI CARDS ===================== -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5 mb-7">
            <div class="stat-card surface p-4 border-l-4 border-l-indigo-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Tasks</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $totalDayTasks ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">On this date</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-amber-400">
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-500">Assigned</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayAssigned ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">In active queue</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-blue-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-blue-500">In Progress</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayInProgress ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">With expert</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-rose-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-rose-500">Rework</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayRework ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Needs revision</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-emerald-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-500">Completed</span>
                <h3 class="text-2xl font-extrabold text-emerald-600 mt-1"><?= $dayCompleted ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Approved & ready</p>
            </div>

            <div class="stat-card surface p-4 bg-gradient-to-br from-indigo-900 to-slate-900 text-white border-none shadow-md">
                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-300">Word Count</span>
                <h3 class="text-2xl font-extrabold text-white mt-1"><?= number_format($dayWords) ?></h3>
                <p class="text-[10px] text-indigo-200 font-bold mt-0.5"><?= $dayUrgent > 0 ? "⚠️ {$dayUrgent} Urgent" : "Regular flow" ?></p>
            </div>
        </div>

        <!-- ===================== SEARCH & FILTER BAR ===================== -->
        <div class="surface p-4 mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="relative w-full md:w-96">
                <span class="material-icons-round absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                <input type="text" id="taskSearch" placeholder="Search by task key, title, client, expert..."
                       class="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto pb-1 md:pb-0">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mr-1">Filter:</span>
                <button onclick="filterStatus('all')" class="status-pill px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 text-white" data-status="all">All (<?= $totalDayTasks ?>)</button>
                <button onclick="filterStatus('assigned')" class="status-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100" data-status="assigned">Assigned</button>
                <button onclick="filterStatus('in_progress')" class="status-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100" data-status="in_progress">Working</button>
                <button onclick="filterStatus('completed')" class="status-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100" data-status="completed">Completed</button>
                <button onclick="filterStatus('rework')" class="status-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100" data-status="rework">Rework</button>
            </div>
        </div>

        <!-- ===================== TASKS TABLE ===================== -->
        <div class="surface overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-icons-round text-indigo-500 text-lg">format_list_bulleted</span>
                    <h3 class="font-bold text-slate-800 text-sm">Tasks List for <?= date('d M Y', strtotime($selectedDate)) ?></h3>
                </div>
                <span class="text-xs text-slate-400 font-bold" id="taskCountDisplay"><?= count($tasks) ?> record<?= count($tasks) === 1 ? '' : 's' ?></span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tasksTable">
                    <thead>
                        <tr class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4">Task Key & Name</th>
                            <th class="px-6 py-4">Client / Subject</th>
                            <th class="px-6 py-4">Words</th>
                            <th class="px-6 py-4">Assigned & Delivery (Deadline)</th>
                            <th class="px-6 py-4">Assigned Expert</th>
                            <th class="px-6 py-4 text-center">Drive Subfolders</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs font-medium">
                        <?php if (count($tasks) > 0): ?>
                            <?php foreach ($tasks as $t): 
                                $clientLink = getDriveClientLink($t);
                                $opsLink    = getDriveOperationsLink($t);
                                $assignedDt = $t['task_created_at'] ?? $t['created_at'];
                                $isTodayAssigned = (!empty($assignedDt) && date('Y-m-d', strtotime($assignedDt)) === date('Y-m-d'));
                                $isTodayDeadline = (!empty($t['deadline']) && date('Y-m-d', strtotime($t['deadline'])) === date('Y-m-d'));
                            ?>
                            <tr class="task-row hover:bg-slate-50/70 transition-colors" data-status="<?= htmlspecialchars($t['status']) ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-slate-800 text-sm tracking-tight block">
                                            <?= htmlspecialchars($t['task_key']) ?>
                                        </span>
                                        <?php if (($t['urgent'] ?? '') === 'yes'): ?>
                                        <span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-black rounded uppercase tracking-wider">Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[11px] text-slate-500 font-semibold truncate max-w-xs mt-0.5">
                                        <?= htmlspecialchars($t['task_name']) ?>
                                    </p>
                                    <?php if (!empty($t['team_notes'])): ?>
                                    <div class="mt-1 flex items-start gap-1 max-w-xs">
                                        <span class="material-icons-round text-amber-500 text-xs shrink-0 mt-0.5">edit_note</span>
                                        <p class="text-[10px] text-amber-800 font-semibold truncate bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200/60" title="Expert Note: <?= htmlspecialchars($t['team_notes'], ENT_QUOTES) ?>">
                                            <span class="font-extrabold text-amber-900">Note:</span> <?= htmlspecialchars($t['team_notes']) ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <span class="font-bold text-slate-700"><?= htmlspecialchars($t['client_name'] ?? 'N/A') ?></span>
                                    <span class="block text-[10px] text-slate-400"><?= htmlspecialchars($t['subject'] ?? 'General') ?></span>
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

                                <td class="px-6 py-4">
                                    <?php if (!empty($t['expert_name'])): ?>
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 font-black text-[10px] flex items-center justify-center">
                                            <?= strtoupper(substr($t['expert_name'], 0, 1)) ?>
                                        </div>
                                        <span class="font-bold text-slate-700"><?= htmlspecialchars($t['expert_name']) ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-amber-600 font-bold italic text-[11px]">Unassigned</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-1.5">
                                        <?php if (!empty($clientLink)): ?>
                                        <a href="<?= htmlspecialchars($clientLink) ?>" target="_blank"
                                           class="drive-btn inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Client Files Subfolder">
                                            <span class="material-icons-round text-sm">folder_shared</span> Client
                                        </a>
                                        <?php endif; ?>

                                        <?php if (!empty($opsLink)): ?>
                                        <a href="<?= htmlspecialchars($opsLink) ?>" target="_blank"
                                           class="drive-btn inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Operations Files Subfolder">
                                            <span class="material-icons-round text-sm">inventory_2</span> Ops
                                        </a>
                                        <?php endif; ?>

                                        <?php if (empty($clientLink) && empty($opsLink)): ?>
                                        <span class="text-slate-300 text-[10px] italic">No drive link</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 border text-[10px] font-bold rounded-lg uppercase tracking-wider <?= getStatusBadge($t['status']) ?>">
                                        <?= str_replace('_', ' ', $t['status']) ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <a href="incoming_tasks.php" 
                                           class="px-2.5 py-1.5 bg-slate-100 hover:bg-indigo-600 hover:text-white text-slate-700 rounded-lg text-xs font-bold transition-colors">
                                            Manage
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-300 mb-3">
                                            <span class="material-icons-round text-3xl">event_busy</span>
                                        </div>
                                        <h4 class="text-sm font-bold text-slate-700">No tasks found for <?= date('d M Y', strtotime($selectedDate)) ?></h4>
                                        <p class="text-xs text-slate-400 mt-1">Try selecting another date or jump back to today.</p>
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
// Live Search
document.getElementById('taskSearch').addEventListener('input', function() {
    const val = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.task-row');
    let visible = 0;

    rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        if (text.includes(val)) {
            r.style.display = '';
            visible++;
        } else {
            r.style.display = 'none';
        }
    });

    document.getElementById('taskCountDisplay').innerText = visible + ' records';
});

// Status Filter Pills
function filterStatus(status) {
    document.querySelectorAll('.status-pill').forEach(btn => {
        if (btn.getAttribute('data-status') === status) {
            btn.className = 'status-pill px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 text-white';
        } else {
            btn.className = 'status-pill px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100';
        }
    });

    const rows = document.querySelectorAll('.task-row');
    let visible = 0;
    rows.forEach(r => {
        if (status === 'all' || r.getAttribute('data-status') === status) {
            r.style.display = '';
            visible++;
        } else {
            r.style.display = 'none';
        }
    });
    document.getElementById('taskCountDisplay').innerText = visible + ' records';
}
</script>

</body>
</html>
